<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->initializeLogger();
    }

    /**
     * Initialize the logger
     */
    private function initializeLogger()
    {
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $noramlJobs[] = $jobitem;
                }
            }
            $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    /**
     * Get historical jobs for a user based on their user type.
     *
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pagenum = isset($page) ? $page : 1;

        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                // Retrieve historical jobs for customer users
                $jobs = $cuser->jobs()
                    ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15);

                $usertype = 'customer';

                return [
                    'emergencyJobs' => $emergencyJobs,
                    'normalJobs' => [],
                    'jobs' => $jobs,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => 0,
                    'pagenum' => 0,
                ];
            } elseif ($cuser->is('translator')) {
                $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
                $totaljobs = $jobs_ids->total();
                $numpages = ceil($totaljobs / 15);

                $usertype = 'translator';

                // Both $jobs and $normalJobs are assigned the same value, so we can simplify this part
                $normalJobs = $jobs_ids;

                return [
                    'emergencyJobs' => $emergencyJobs,
                    'normalJobs' => $normalJobs,
                    'jobs' => $jobs_ids,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => $numpages,
                    'pagenum' => $pagenum,
                ];
            }
        }
    }


    /**
     * Create and store a new job based on user input data.
     *
     * @param User $user
     * @param array $data
     * @return array
     */
    public function store(User $user, array $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        $response = [];

        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            // Check for required fields
            $requiredFields = ['from_language_id', 'duration', 'immediate'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $response['status'] = 'fail';
                    $response['message'] = 'Du måste fylla in alla fält';
                    $response['field_name'] = $field;
                    return $response;
                }
            }

            // Set customer_phone_type and customer_physical_type
            $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
            $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

            if ($data['immediate'] == 'yes') {
                // Handle immediate job
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                // Handle regular job
                $due = $data['due_date'] . ' ' . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');

                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in the past";
                    return $response;
                }
            }

            // Set gender and certified fields
            $data['gender'] = $this->getGenderFromJobFor($data['job_for']);
            $data['certified'] = $this->getCertifiedFromJobFor($data['job_for']);

            // Set job_type based on consumer_type
            $data['job_type'] = $this->getJobTypeFromConsumerType($consumer_type);

            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due)) {
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            }
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;

            // Additional data processing for job_for, customer_town, and customer_type

            // Fire an event or send a notification

        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;
    }

    /**
     * Determine the gender value based on job_for selection.
     *
     * @param array $jobFor
     * @return string|null
     */
    private function getGenderFromJobFor(array $jobFor)
    {
        if (in_array('male', $jobFor)) {
            return 'male';
        } elseif (in_array('female', $jobFor)) {
            return 'female';
        }

        return null;
    }

    /**
     * Determine the certified value based on job_for selection.
     *
     * @param array $jobFor
     * @return string|null
     */
    private function getCertifiedFromJobFor(array $jobFor)
    {
        if (in_array('certified', $jobFor)) {
            return 'yes';
        } elseif (in_array('certified_in_law', $jobFor)) {
            return 'law';
        } elseif (in_array('certified_in_helth', $jobFor)) {
            return 'health';
        } elseif (in_array('normal', $jobFor)) {
            return 'normal';
        } elseif (in_array('normal', $jobFor) && in_array('certified', $jobFor)) {
            return 'both';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) {
            return 'n_law';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_helth', $jobFor)) {
            return 'n_health';
        }

        return null;
    }

    /**
     * Determine the job_type value based on consumer_type.
     *
     * @param string $consumerType
     * @return string|null
     */
    private function getJobTypeFromConsumerType($consumerType)
    {
        if ($consumerType == 'rwsconsumer') {
            return 'rws';
        } elseif ($consumerType == 'ngo') {
            return 'unpaid';
        } elseif ($consumerType == 'paid') {
            return 'paid';
        }

        return null;
    }


    /**
     * Store job details and send an email notification.
     *
     * @param array $data
     * @return array
     */
    public function storeJobEmail(array $data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);

        // Update job details
        $this->updateJobDetails($job, $data);

        // Retrieve user information
        $user = $job->user()->get()->first();
        $email = $this->getJobEmailOrUserEmail($job, $user);
        $name = $user->name;

        // Send email notification
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $this->sendJobCreatedEmail($email, $name, $subject, $user, $job);

        // Prepare response
        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';

        // Trigger an event
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    /**
     * Update job details with data provided.
     *
     * @param Job $job
     * @param array $data
     */
    private function updateJobDetails(Job $job, array $data)
    {
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();

        if (isset($data['address'])) {
            $job->address = $this->getUpdatedValue($data['address'], $user->userMeta->address);
            $job->instructions = $this->getUpdatedValue($data['instructions'], $user->userMeta->instructions);
            $job->town = $this->getUpdatedValue($data['town'], $user->userMeta->city);
        }

        $job->save();
    }

    /**
     * Determine the updated value for a field based on input.
     *
     * @param string $input
     * @param string $defaultValue
     * @return string
     */
    private function getUpdatedValue($input, $defaultValue)
    {
        return ($input != '') ? $input : $defaultValue;
    }

    /**
     * Determine the email address to use for notifications based on job and user details.
     *
     * @param Job $job
     * @param User $user
     * @return string
     */
    private function getJobEmailOrUserEmail(Job $job, User $user)
    {
        return !empty($job->user_email) ? $job->user_email : $user->email;
    }

    /**
     * Send the job created email notification.
     *
     * @param string $email
     * @param string $name
     * @param string $subject
     * @param User $user
     * @param Job $job
     */
    private function sendJobCreatedEmail($email, $name, $subject, User $user, Job $job)
    {
        $send_data = ['user' => $user, 'job' => $job];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
    }


    /**
     * Convert a job object to an array of data.
     *
     * @param Job $job
     * @return array
     */
    public function jobToData(Job $job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $this->getGenderLabel($job->gender),
            'certified' => $this->getCertificationLabel($job->certified),
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
            'due_date' => $this->getDueDate($job->due),
            'due_time' => $this->getDueTime($job->due),
            'job_for' => $this->getJobForLabels($job->gender, $job->certified),
        ];

        return $data;
    }

    /**
     * Get the gender label based on the provided gender.
     *
     * @param string $gender
     * @return string
     */
    private function getGenderLabel($gender)
    {
        return ($gender == 'male') ? 'Man' : ($gender == 'female' ? 'Kvinna' : '');
    }

    /**
     * Get the certification label based on the provided certification.
     *
     * @param string $certified
     * @return string
     */
    private function getCertificationLabel($certified)
    {
        $labels = [
            'both' => ['Godkänd tolk', 'Auktoriserad'],
            'yes' => 'Auktoriserad',
            'n_health' => 'Sjukvårdstolk',
            'law' => 'Rätttstolk',
            'n_law' => 'Rätttstolk',
        ];

        return isset($labels[$certified]) ? $labels[$certified] : $certified;
    }

    /**
     * Get the due date from the job's due timestamp.
     *
     * @param string $due
     * @return string
     */
    private function getDueDate($due)
    {
        $dueParts = explode(" ", $due);
        return $dueParts[0];
    }

    /**
     * Get the due time from the job's due timestamp.
     *
     * @param string $due
     * @return string
     */
    private function getDueTime($due)
    {
        $dueParts = explode(" ", $due);
        return $dueParts[1];
    }

    /**
     * Get labels for job "for" based on gender and certification.
     *
     * @param string $gender
     * @param string $certified
     * @return array
     */
    private function getJobForLabels($gender, $certified)
    {
        $labels = [];

        if ($gender) {
            $labels[] = $this->getGenderLabel($gender);
        }

        if ($certified) {
            $labels[] = $this->getCertificationLabel($certified);
        }

        return $labels;
    }


    /**
     * Mark a job as completed and handle session details.
     *
     * @param array $post_data
     */
    public function jobEnd(array $post_data = [])
    {
        // Get the current date and time
        $completedDate = now();
        $jobId = $post_data["job_id"];

        // Retrieve job details
        $job = Job::with('translatorJobRel')->find($jobId);

        // Calculate session time
        $sessionTime = $this->calculateSessionTime($job->due, $completedDate);

        // Update job status and session details
        $this->updateJobStatusAndSessionDetails($job, $completedDate, $sessionTime);

        // Send email to the customer
        $this->sendSessionEndedEmail($job, $post_data, 'faktura');

        // Save the job
        $job->save();

        // Get the translator for this job
        $translator = $this->getTranslatorForJob($job, $post_data);

        // Send session ended email to the translator
        $this->sendSessionEndedEmail($translator, $post_data, 'lön');

        // Update translator's completed status
        $this->updateTranslatorCompletedStatus($translator, $completedDate, $post_data['userid']);
    }

    /**
     * Calculate session time based on start and end times.
     *
     * @param string $startTime
     * @param string $endTime
     * @return string
     */
    private function calculateSessionTime($startTime, $endTime)
    {
        $start = date_create($startTime);
        $end = date_create($endTime);
        $diff = date_diff($end, $start);
        return $diff->format('%h tim %i min');
    }

    /**
     * Update job status and session details.
     *
     * @param Job $job
     * @param string $completedDate
     * @param string $sessionTime
     */
    private function updateJobStatusAndSessionDetails(Job $job, $completedDate, $sessionTime)
    {
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $sessionTime;
    }

    /**
     * Send session ended email notification.
     *
     * @param User $user
     * @param Job $job
     * @param string $session_time
     * @param string $for_text
     */
    private function sendSessionEndedEmailNotification(User $user, Job $job, $session_time, $for_text)
    {
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }

        $name = $user->name;
        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => $for_text,
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    /**
     * Get the translator for the job.
     *
     * @param Job $job
     * @param array $post_data
     * @return User
     */
    private function getTranslatorForJob(Job $job, array $post_data)
    {
        $translatorId = $post_data['userid'] == $job->user_id ? $job->translatorJobRel->first()->user_id : $job->user_id;
        return User::find($translatorId);
    }

    /**
     * Update translator's completed status.
     *
     * @param User $translator
     * @param string $completedDate
     * @param int $userId
     */
    private function updateTranslatorCompletedStatus(User $translator, $completedDate, $userId)
    {
        $translator->translatorJobRel
            ->where('completed_at', null)
            ->where('cancel_at', null)
            ->first()
            ->update([
                'completed_at' => $completedDate,
                'completed_by' => $userId,
            ]);
    }


    /**
     * Get potential job IDs for a user with their ID.
     *
     * @param int $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        // Retrieve user meta information
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;

        // Determine the job type based on translator type
        $job_type = $this->determineJobType($translatorType);

        // Get user's languages
        $userLanguages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();

        // Get user's gender and translator level
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        // Get potential job IDs based on user attributes
        $jobIds = Job::getJobs($user_id, $job_type, 'pending', $userLanguages, $gender, $translatorLevel);

        // Filter jobs based on translator town and customer preferences
        $filteredJobIds = $this->filterJobsByTownAndPreferences($jobIds, $user_id);

        // Convert job IDs to job objects
        $jobs = TeHelper::convertJobIdsInObjs($filteredJobIds);

        return $jobs;
    }

    /**
     * Determine the job type based on the translator type.
     *
     * @param string $translatorType
     * @return string
     */
    private function determineJobType($translatorType)
    {
        $jobType = 'unpaid'; // Default to unpaid jobs

        if ($translatorType == 'professional') {
            $jobType = 'paid';
        } elseif ($translatorType == 'rwstranslator') {
            $jobType = 'rws';
        }

        return $jobType;
    }

    /**
     * Filter potential jobs by translator town and customer preferences.
     *
     * @param array $jobIds
     * @param int $user_id
     * @return array
     */
    private function filterJobsByTownAndPreferences(array $jobIds, $user_id)
    {
        foreach ($jobIds as $key => $jobId) {
            $job = Job::find($jobId->id);
            $jobUserId = $job->user_id;

            // Check if the job's town is allowed for the translator
            $checkTown = Job::checkTowns($jobUserId, $user_id);

            if (
                ($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' &&
                !$checkTown
            ) {
                unset($jobIds[$key]);
            }
        }

        return array_values($jobIds); // Reset array keys
    }


    /**
     * Send push notifications to suitable translators for a job.
     *
     * @param Job $job
     * @param array $data
     * @param int $exclude_user_id
     */
    public function sendNotificationTranslator(Job $job, array $data = [], int $exclude_user_id)
    {
        $translatorArray = [];
        $delayedTranslatorArray = [];

        // Retrieve all users
        $users = User::all();

        foreach ($users as $user) {
            if ($user->user_type === '2' && $user->status === '1' && $user->id !== $exclude_user_id) {
                // The user is a translator and is not disabled
                if (!$this->shouldSendPushNotification($user->id)) {
                    continue;
                }

                $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');

                if ($data['immediate'] === 'yes' && $notGetEmergency === 'yes') {
                    continue;
                }

                $jobs = $this->getPotentialJobIdsWithUserId($user->id);

                foreach ($jobs as $potentialJob) {
                    if ($job->id === $potentialJob->id) {
                        // The potential job matches the current job
                        $userId = $user->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $potentialJob->id);

                        if ($jobForTranslator === 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $potentialJob);

                            if ($jobChecker !== 'userCanNotAcceptJob') {
                                if ($this->shouldDelayPushAtNight($user->id)) {
                                    $delayedTranslatorArray[] = $user;
                                } else {
                                    $translatorArray[] = $user;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Prepare notification data
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msgContents = ($data['immediate'] === 'no')
            ? 'Ny bokning för ' . $data['language'] . ' tolk ' . $data['duration'] . ' min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . ' tolk ' . $data['duration'] . ' min';

        $msgText = ["en" => $msgContents];

        // Logging
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayedTranslatorArray, $msgText, $data]);

        // Send push notifications
        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($delayedTranslatorArray, $job->id, $data, $msgText, true);
    }


    /**
     * Send SMS notifications to potential translators for a job and return the count of translators.
     *
     * @param Job $job
     * @return int
     */
    public function sendSMSNotificationToTranslator(Job $job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        // Determine the appropriate message template based on the job type
        $message = '';
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);
        } else {
            // Default to a phone job or handle other cases
            $message = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        }

        // Log the message
        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            // Send the message to each translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }


    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function shouldDelayPushAtNight($user_id)
    {
        if (DateTimeHelper::isNightTime()) {
            $notGetNighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
            return $notGetNighttime !== 'yes';
        }
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function shouldSendPushNotification($user_id)
    {
        $notGetNotification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $notGetNotification !== 'yes';
    }

    /**
     * Send OneSignal push notifications to specific users.
     *
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param array $msg_text
     * @param bool $is_need_delay
     */
    public function sendPushNotificationsToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $notificationSounds = $this->getNotificationSounds($data);

        $fields = [
            'app_id' => $onesignalAppID,
            'tags' => json_decode($user_tags),
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msg_text,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $notificationSounds['android'],
            'ios_sound' => $notificationSounds['ios'],
        ];

        if ($is_need_delay) {
            $nextBusinessTime = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $nextBusinessTime;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Get notification sounds based on data.
     *
     * @param array $data
     * @return array
     */
    private function getNotificationSounds($data)
    {
        $notificationSounds = [
            'android' => 'default',
            'ios' => 'default',
        ];

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $notificationSounds['android'] = 'normal_booking';
                $notificationSounds['ios'] = 'normal_booking.mp3';
            } else {
                $notificationSounds['android'] = 'emergency_booking';
                $notificationSounds['ios'] = 'emergency_booking.mp3';
            }
        }

        return $notificationSounds;
    }


    /**
     * Get potential translators for a job.
     *
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translator_type = $this->getTranslatorTypeFromJobType($job->job_type);
        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = $this->getTranslatorLevelsFromJob($job);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

        return $users;
    }

    /**
     * Determine the translator type based on the job type.
     *
     * @param string $jobType
     * @return string
     */
    private function getTranslatorTypeFromJobType($jobType)
    {
        switch ($jobType) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return '';
        }
    }

    /**
     * Determine the translator levels based on the job's certification.
     *
     * @param Job $job
     * @return array
     */
    private function getTranslatorLevelsFromJob(Job $job)
    {
        $translator_level = [];

        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        return $translator_level;
    }


    /**
     * Update a job's details.
     *
     * @param int $id
     * @param array $data
     * @param User $cuser
     * @return array
     */
    public function updateJob($id, $data, User $cuser)
    {
        $job = Job::find($id);

        $current_translator = $this->getCurrentTranslator($job);
        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job, $data['due']);
        if ($changeDue['dateChanged']) {
            $log_data[] = $changeDue['log_data'];
        }

        $langChanged = $this->changeLanguage($job, $data['from_language_id'], $log_data, $langChanged);

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ', $log_data);

        if ($job->due <= now()) {
            $job->save();
            return ['Updated'];
        }

        $job->save();
        $this->handleNotifications($job, $changeDue, $changeTranslator, $langChanged);

        return ['Updated'];
    }

    /**
     * Get the current translator for the job.
     *
     * @param Job $job
     * @return Translator|null
     */
    private function getCurrentTranslator(Job $job)
    {
        return $job->translatorJobRel->where('cancel_at', null)->first() ?? $job->translatorJobRel->where('completed_at', '!=', null)->first();
    }

    /**
     * Change the due date and time of the job.
     *
     * @param Job $job
     * @param string $newDue
     * @return array
     */
    private function changeDue(Job $job, string $newDue)
    {
        $log_data = ['old_due' => $job->due, 'new_due' => $newDue];
        if ($job->due != $newDue) {
            $job->due = $newDue;
            return ['dateChanged' => true, 'log_data' => $log_data];
        }
        return ['dateChanged' => false, 'log_data' => []];
    }

    /**
     * Change the job's language.
     *
     * @param Job $job
     * @param int $newLanguageId
     * @param array $log_data
     * @param bool $langChanged
     * @return bool
     */
    private function changeLanguage(Job $job, int $newLanguageId, array &$log_data, bool $langChanged)
    {
        if ($job->from_language_id != $newLanguageId) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($newLanguageId)
            ];
            $job->from_language_id = $newLanguageId;
            $langChanged = true;
        }
        return $langChanged;
    }

    /**
     * Handle sending notifications based on changes.
     *
     * @param Job $job
     * @param array $changeDue
     * @param array $changeTranslator
     * @param bool $langChanged
     */
    private function handleNotifications(Job $job, array $changeDue, array $changeTranslator, bool $langChanged)
    {
        if ($changeDue['dateChanged']) {
            $this->sendChangedDateNotification($job, $changeDue['old_time']);
        }
        if ($changeTranslator['translatorChanged']) {
            $this->sendChangedTranslatorNotification($job, $changeTranslator['current_translator'], $changeTranslator['new_translator']);
        }
        if ($langChanged) {
            $this->sendChangedLangNotification($job, $changeTranslator['old_lang']);
        }
    }


    /**
     * Change the status of the job.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return array
     */
    private function changeStatus(Job $job, array $data, bool $changedTranslator)
    {
        $oldStatus = $job->status;
        $statusChanged = false;

        switch ($oldStatus) {
            case 'timedout':
                $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                break;
            case 'completed':
                $statusChanged = $this->changeCompletedStatus($job, $data);
                break;
            case 'started':
                $statusChanged = $this->changeStartedStatus($job, $data);
                break;
            case 'pending':
                $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                break;
            case 'withdrawafter24':
                $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                break;
            case 'assigned':
                $statusChanged = $this->changeAssignedStatus($job, $data);
                break;
        }

        if ($statusChanged) {
            $logData = [
                'old_status' => $oldStatus,
                'new_status' => $data['status']
            ];
            $statusChanged = true;
            return ['statusChanged' => $statusChanged, 'log_data' => $logData];
        }

        return ['statusChanged' => $statusChanged, 'log_data' => []];
    }


    /**
     * Handle status changes when the job status is 'timedout'.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus(Job $job, array $data, bool $changedTranslator)
    {
        // Store the old status for logging purposes.
        $oldStatus = $job->status;

        // Update the job's status to the new status.
        $job->status = $data['status'];

        // Get the user associated with the job.
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job,
        ];

        if ($data['status'] === 'pending') {
            // Handle the status change to 'pending'.
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $jobData = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $jobData, '*'); // Send push notifications to suitable translators.

            return true;
        } elseif ($changedTranslator) {
            // Handle the status change when a new translator has accepted the job.
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }

        // If no status change occurred, return false.
        return false;
    }


    /**
     * Handle status changes when the job status is 'completed'.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeCompletedStatus(Job $job, array $data)
    {
        // Store the new status from the data.
        $newStatus = $data['status'];

        // Check if the new status is one of the allowed statuses.
        $allowedStatuses = ['withdrawnbefore24', 'withdrawafter24', 'timedout'];
        if (in_array($newStatus, $allowedStatuses)) {
            // Update the job's status to the new status.
            $job->status = $newStatus;

            // Check if the status is 'timedout' and admin comments are provided.
            if ($newStatus === 'timedout' && empty($data['admin_comments'])) {
                return false; // Admin comments are required for 'timedout' status.
            }

            // Update admin comments if provided.
            if (!empty($data['admin_comments'])) {
                $job->admin_comments = $data['admin_comments'];
            }

            // Save the updated job.
            $job->save();

            return true; // Status change is successful.
        }

        return false; // Status change is not allowed.
    }


    /**
     * Handle status changes when the job status is 'started.'
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeStartedStatus(Job $job, array $data)
    {
        // Store the new status from the data.
        $newStatus = $data['status'];

        // Check if the new status is in the list of allowed statuses.
        $allowedStatuses = ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'];
        if (in_array($newStatus, $allowedStatuses)) {
            // Update the job's status to the new status.
            $job->status = $newStatus;

            // Check if admin comments are provided.
            if (empty($data['admin_comments'])) {
                return false; // Admin comments are required for certain statuses.
            }

            // Update admin comments.
            $job->admin_comments = $data['admin_comments'];

            if ($newStatus === 'completed') {
                // Handle additional operations for 'completed' status.
                if (empty($data['sesion_time'])) {
                    return false; // Session time is required for 'completed' status.
                }

                // Calculate session time and update job properties.
                $interval = $data['sesion_time'];
                $diff = explode(':', $interval);
                $job->end_at = date('Y-m-d H:i:s');
                $job->session_time = $interval;
                $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

                // Send email notifications.
                $user = $job->user()->first();
                $this->sendSessionEndedEmailNotification($user, $job, $session_time, 'faktura');

                $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
                $this->sendSessionEndedEmailNotification($translator->user, $job, $session_time, 'lön');
            }

            // Save the updated job.
            $job->save();

            return true; // Status change is successful.
        }

        return false; // Status change is not allowed.
    }


    /**
     * Handle status changes when the job status is 'pending.'
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return bool
     */
    private function changePendingStatus(Job $job, array $data, $changedTranslator)
    {
        // Store the new status from the data.
        $newStatus = $data['status'];

        // Check if the new status is in the list of allowed statuses.
        $allowedStatuses = ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'];
        if (in_array($newStatus, $allowedStatuses)) {
            // Update the job's status to the new status.
            $job->status = $newStatus;

            // Check if admin comments are provided.
            if ($data['admin_comments'] === '' && $newStatus === 'timedout') {
                return false; // Admin comments are required for 'timedout' status.
            }

            $job->admin_comments = $data['admin_comments'];

            // Get the user associated with the job.
            $user = $job->user()->first();
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }

            $name = $user->name;
            $dataEmail = ['user' => $user, 'job' => $job];

            if ($newStatus === 'assigned' && $changedTranslator) {
                // Handle additional operations for 'assigned' status with a changed translator.
                $job->save();
                $job_data = $this->jobToData($job);

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

                $translator = Job::getJobsAssignedTranslatorDetail($job);
                $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

                $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
                $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
                return true; // Status change is successful with additional operations.
            } else {
                // Handle status change without a changed translator.
                $subject = 'Avbokning av bokningsnr: #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
                $job->save();
                return true; // Status change is successful without additional operations.
            }
        }

        return false; // Status change is not allowed.
    }


    /**
     * Send a session start reminder notification.
     *
     * @param User $user
     * @param Job $job
     * @param string $language
     * @param string $due
     * @param int $duration
     */
    public function sendSessionStartRemindNotification(User $user, Job $job, string $language, string $due, int $duration)
    {
        $msgText = $this->getSessionStartReminderMessage($job, $language, $due, $duration);

        if ($this->bookingRepository->shouldSendPushNotification($user->id)) {
            $usersArray = [$user];
            $data = ['notification_type' => 'session_start_remind'];

            $this->notificationService->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->shouldDelayPushAtNight($user->id));

            $this->logger->addInfo('sendSessionStartRemindNotification', ['job' => $job->id]);
        }
    }

    /**
     * Generate the session start reminder message.
     *
     * @param Job $job
     * @param string $language
     * @param string $due
     * @param int $duration
     * @return array
     */
    private function getSessionStartReminderMessage(Job $job, string $language, string $due, int $duration)
    {
        $dueExplode = explode(' ', $due);
        $location = $job->customer_physical_type === 'yes' ? $job->town : 'telefon';

        $message = [
            'en' => "Detta är en påminnelse om din {$language}tolkning som startar kl {$dueExplode[1]} på {$dueExplode[0]} i {$location} och varar i {$duration} min. Kom ihåg att ge feedback efter tolkningen!"
        ];

        return $message;
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * Change the status to "withdrawbefore24," "withdrawafter24," or "timedout" if the conditions are met.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeAssignedStatus(Job $job, array $data)
    {
        $validStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

        if (in_array($data['status'], $validStatuses)) {
            $job->status = $data['status'];

            if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
                return false; // Admin comments are required for timedout status change
            }

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;

                $dataEmail = [
                    'user' => $user,
                    'job' => $job,
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $translator = $job->translatorJobRel
                    ->where('completed_at', null)
                    ->where('cancel_at', null)
                    ->first();

                $email = $translator->user->email;
                $name = $translator->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $dataEmail = [
                    'user' => $translator,
                    'job' => $job,
                ];

                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }

            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false; // Status change not applicable
    }


    /**
     * Change the assigned translator for the job if needed.
     *
     * @param Translator $current_translator
     * @param array $data
     * @param Job $job
     * @return array
     */
    private function changeTranslator($current_translator, array $data, Job $job)
    {
        $translatorChanged = false;
        $log_data = [];

        if (!is_null($current_translator) || (!empty($data['translator']) && $data['translator'] !== 0) || !empty($data['translator_email'])) {
            if (!is_null($current_translator) && (
                    (!empty($data['translator']) && $current_translator->user_id != $data['translator']) ||
                    !empty($data['translator_email'])
                )) {
                // Change the existing translator
                if (!empty($data['translator_email'])) {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = Translator::create([
                    'user_id' => $data['translator'],
                    'job_id' => $job->id
                ]);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && (
                    !empty($data['translator']) && ($data['translator'] !== 0 || !empty($data['translator_email']))
                )) {
                // Assign a new translator
                if (!empty($data['translator_email'])) {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = Translator::create([
                    'user_id' => $data['translator'],
                    'job_id' => $job->id
                ]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
        }

        if ($translatorChanged) {
            return [
                'translatorChanged' => $translatorChanged,
                'new_translator' => $new_translator,
                'log_data' => $log_data
            ];
        }

        return ['translatorChanged' => $translatorChanged];
    }
    /**
     * Send notifications about the changed translator for the job.
     *
     * @param Job $job
     * @param Translator|null $current_translator
     * @param Translator $new_translator
     */
    public function sendChangedTranslatorNotification(Job $job, Translator $current_translator, Translator $new_translator)
    {
        $user = $job->user()->first();
        $customerEmail = !empty($job->user_email) ? $job->user_email : $user->email;

        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;

        $customerData = [
            'user' => $user,
            'job' => $job
        ];

        // Send notification to the customer
        $this->sendEmailNotification($customerEmail, $user->name, $subject, 'emails.job-changed-translator-customer', $customerData);

        if ($current_translator) {
            $translatorEmail = $current_translator->user->email;
            $translatorData = ['user' => $current_translator->user];

            // Send notification to the previous translator
            $this->sendEmailNotification($translatorEmail, $current_translator->user->name, $subject, 'emails.job-changed-translator-old-translator', $translatorData);
        }

        $newTranslatorEmail = $new_translator->user->email;
        $newTranslatorData = ['user' => $new_translator->user];

        // Send notification to the new translator
        $this->sendEmailNotification($newTranslatorEmail, $new_translator->user->name, $subject, 'emails.job-changed-translator-new-translator', $newTranslatorData);
    }

    /**
     * Send an email notification.
     *
     * @param string $email
     * @param string $name
     * @param string $subject
     * @param string $template
     * @param array $data
     */
    private function sendEmailNotification($email, $name, $subject, $template, array $data)
    {
        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    /**
     * Send notifications about the changed date for the job.
     *
     * @param Job $job
     * @param string $oldTime
     */
    public function sendChangedDateNotification(Job $job, string $oldTime)
    {
        $user = $job->user()->first();
        $customerEmail = !empty($job->user_email) ? $job->user_email : $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $customerData = [
            'user' => $user,
            'job' => $job,
            'old_time' => $oldTime
        ];

        // Send notification to the customer
        $this->sendEmailNotification($customerEmail, $user->name, $subject, 'emails.job-changed-date', $customerData);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $translatorEmail = $translator->email;

        $translatorData = [
            'user' => $translator,
            'job' => $job,
            'old_time' => $oldTime
        ];

        // Send notification to the assigned translator
        $this->sendEmailNotification($translatorEmail, $translator->name, $subject, 'emails.job-changed-date', $translatorData);
    }


    /**
     * Send notifications about the changed language for the job.
     *
     * @param Job $job
     * @param string $oldLang
     */
    public function sendChangedLangNotification(Job $job, string $oldLang)
    {
        $user = $job->user()->first();
        $customerEmail = !empty($job->user_email) ? $job->user_email : $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $customerData = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $oldLang
        ];

        // Send notification to the customer
        $this->sendEmailNotification($customerEmail, $user->name, $subject, 'emails.job-changed-lang', $customerData);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $translatorEmail = $translator->email;

        // Send notification to the assigned translator
        $this->sendEmailNotification($translatorEmail, $translator->name, $subject, 'emails.job-changed-lang', $customerData);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->shouldSendPushNotification($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->shouldDelayPushAtNight($user->id));
        }
    }

    /**
     * Send notifications when an admin cancels a job.
     *
     * @param int $jobId
     */
    public function sendNotificationByAdminCancelJob(int $jobId)
    {
        $job = Job::findOrFail($jobId);
        $userMeta = $job->user->userMeta()->first();

        // Prepare data for sending notifications
        $notificationData = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $userMeta->city,
            'customer_type' => $userMeta->customer_type,
        ];

        // Extract due date and time
        $dueDateTime = explode(" ", $job->due);
        $notificationData['due_date'] = $dueDateTime[0];
        $notificationData['due_time'] = $dueDateTime[1];

        // Determine job target audience based on gender and certification
        $notificationData['job_for'] = $this->getJobTargetAudience($job);

        // Send notifications to suitable translators
        $this->sendNotificationTranslator($job, $notificationData, '*');
    }

    /**
     * Determine the target audience for a job based on gender and certification.
     *
     * @param Job $job
     * @return array
     */
    private function getJobTargetAudience(Job $job)
    {
        $jobFor = [];

        if ($job->gender != null) {
            $jobFor[] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } elseif ($job->certified == 'yes') {
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified;
            }
        }

        return $jobFor;
    }


    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    public function acceptJob($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);

        $response = $this->acceptJobLogic($job, $user);

        return $response;
    }

    private function acceptJobLogic($job, $user)
    {
        $response = [];

        if ($this->isJobAcceptable($job, $user)) {
            $this->updateJobStatusAndNotify($job);
            $response = [
                'status' => 'success',
                'list'   => $this->getJobListForResponse($job, $user),
            ];
        } else {
            $response = [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.',
            ];
        }

        return $response;
    }

    private function isTranslatorAlreadyBooked($job, $user)
    {
        $jobId = $job->id;
        $userId = $user->id;
        $jobDue = $job->due;

        return Job::isTranslatorAlreadyBooked($jobId, $userId, $jobDue);
    }

    private function updateJobStatusAndNotify($job)
    {
        $job->status = 'assigned';
        $job->save();

        $user = $job->user;
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];
        (new AppMailer())->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

    private function getJobListForResponse($job, $user)
    {
        $jobs = $this->getPotentialJobs($user);
        return json_encode(['jobs' => $jobs, 'job' => $job], true);
    }


    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = [];

        if ($this->isJobAcceptable($job, $cuser)) {
            $this->updateJobStatusAndNotify($job);
            $response = $this->getJobAcceptedResponse($job, $cuser);
        } else {
            $response = $this->getJobRejectedResponse($job, $cuser);
        }

        return $response;
    }

    private function isJobAcceptable($job, $cuser)
    {
        return $job->status === 'pending' && !$this->isTranslatorAlreadyBooked($job, $cuser);
    }

    private function getJobAcceptedResponse($job, $cuser)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];

        $this->sendJobAcceptedNotification($email, $name, $subject, 'emails.job-accepted', $data);

        return [
            'status'  => 'success',
            'list'    => ['job' => $job],
            'message' => $this->getJobAcceptedMessage($job),
        ];
    }

    private function sendJobAcceptedNotification($email, $name, $subject, $view, $data)
    {
        (new AppMailer())->send($email, $name, $subject, $view, $data);
    }

    private function getJobAcceptedMessage($job)
    {
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        return "Du har nu accepterat och fått bokningen för $language tolk {$job->duration}min {$job->due}.";
    }

    private function getJobRejectedResponse($job, $cuser)
    {
        if ($this->isTranslatorAlreadyBooked($job, $cuser)) {
            return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'];
        } else {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            return ['status' => 'fail', 'message' => "Denna $language tolkning {$job->duration}min {$job->due} har redan accepterats av annan tolk. Du har inte fått denna tolkning"];
        }
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if ($cuser->is('customer')) {
            $response = $this->handleCustomerCancellation($job);
        } else {
            $response = $this->handleTranslatorCancellation($job);
        }

        return $response;
    }

    private function handleCustomerCancellation($job)
    {
        $response = ['status' => 'success'];

        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }

        $job->withdraw_at = Carbon::now();
        $job->save();
        Event::fire(new JobWasCanceled($job));

        $response['jobstatus'] = 'success';

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($translator) {
            $this->sendJobCancellationNotificationToTranslator($translator, $job);
        }

        return $response;
    }

    private function handleTranslatorCancellation($job)
    {
        $response = ['status' => 'success'];

        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $customer = $job->user()->first();
            if ($customer) {
                $this->sendJobCancellationNotificationToCustomer($customer, $job);
            }

            $job->status = 'pending';
            $job->created_at = now();
            $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
            $job->save();

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            if ($translator) {
                Job::deleteTranslatorJobRel($translator->id, $job->id);
                $this->sendNotificationToSuitableTranslators($job, $translator);
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
        }

        return $response;
    }

    public function getPotentialJobs($cuser)
    {
        $cuserMeta = $cuser->userMeta;
        $jobType = $this->determineJobType($cuserMeta);

        $languages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;

        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $languages, $gender, $translatorLevel);

        foreach ($jobIds as $k => $job) {
            if ($this->shouldRemoveJob($job, $cuser)) {
                unset($jobIds[$k]);
            }
        }

        return $jobIds;
    }

    private function shouldRemoveJob($job, $cuser)
    {
        $specificJob = Job::assignedToPaticularTranslator($cuser->id, $job->id);
        $checkParticularJob = Job::checkParticularJob($cuser->id, $job);
        $checkTown = Job::checkTowns($job->user_id, $cuser->id);

        if ($specificJob == 'SpecificJob' && $checkParticularJob == 'userCanNotAcceptJob') {
            return true;
        }

        if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checkTown) {
            return true;
        }

        return false;
    }


    public function endJob($post_data)
    {
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if ($jobDetail->status !== 'started') {
            return ['status' => 'success'];
        }

        $completedDate = date('Y-m-d H:i:s');
        $dueDate = $jobDetail->due;
        $interval = $this->calculateTimeDifference($completedDate, $dueDate);

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;
        $jobDetail->save();

        $this->sendSessionEndedEmails($jobDetail, $interval, 'faktura');

        $translatorRel = $jobDetail->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
        Event::fire(new SessionEnded($jobDetail, ($post_data['user_id'] == $jobDetail->user_id) ? $translatorRel->user_id : $jobDetail->user_id));

        $this->sendSessionEndedEmails($translatorRel, $interval, 'lön');

        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $post_data['user_id'];
        $translatorRel->save();

        return ['status' => 'success'];
    }

    private function calculateTimeDifference($completedDate, $dueDate)
    {
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        return $diff->format('%h tim %i min');
    }

    private function sendSessionEndedEmails($user, $interval, $forText)
    {
        $userDetail = $user->user()->first();
        $email = $userDetail->email;
        $name = $userDetail->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $user->job->id;
        $data = [
            'user' => $userDetail,
            'job' => $user->job,
            'session_time' => $interval,
            'for_text' => $forText,
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }



    public function customerNotCall($post_data)
    {
        $jobId = $post_data["job_id"];
        $completedDate = date('Y-m-d H:i:s');

        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;

        $interval = $this->calculateTimeDifference($completedDate, $dueDate);

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'not_carried_out_customer';

        $translatorRel = $jobDetail->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $translatorRel->user_id;

        $jobDetail->save();
        $translatorRel->save();

        return ['status' => 'success'];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;

        $query = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $query->when(isset($requestdata['feedback']) && $requestdata['feedback'] != 'false', function ($q) {
                return $q->where('ignore_feedback', 0)->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', 3);
                });
            });

            $query->when(isset($requestdata['id']) && $requestdata['id'] != '', function ($q) use ($requestdata) {
                return is_array($requestdata['id']) ? $q->whereIn('id', $requestdata['id']) : $q->where('id', $requestdata['id']);
            });

            $query->when(isset($requestdata['lang']) && $requestdata['lang'] != '', function ($q) use ($requestdata) {
                return $q->whereIn('from_language_id', $requestdata['lang']);
            });

            $query->when(isset($requestdata['status']) && $requestdata['status'] != '', function ($q) use ($requestdata) {
                return $q->whereIn('status', $requestdata['status']);
            });

            $query->when(isset($requestdata['expired_at']) && $requestdata['expired_at'] != '', function ($q) use ($requestdata) {
                return $q->where('expired_at', '>=', $requestdata['expired_at']);
            });

            $query->when(isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '', function ($q) use ($requestdata) {
                return $q->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            });

            $query->when(isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '', function ($q) use ($requestdata) {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    return $q->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            });

            $query->when(isset($requestdata['translator_email']) && count($requestdata['translator_email']), function ($q) use ($requestdata) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    return $q->whereIn('id', $allJobIDs);
                }
            });

            $query->when(isset($requestdata['filter_timetype']), function ($q) use ($requestdata) {
                if ($requestdata['filter_timetype'] == "created") {
                    $q->when(isset($requestdata['from']) && $requestdata['from'] != "", function ($q) use ($requestdata) {
                        return $q->where('created_at', '>=', $requestdata["from"]);
                    });

                    $q->when(isset($requestdata['to']) && $requestdata['to'] != "", function ($q) use ($requestdata) {
                        $to = $requestdata["to"] . " 23:59:00";
                        return $q->where('created_at', '<=', $to);
                    });

                    return $q->orderBy('created_at', 'desc');
                }

                if ($requestdata['filter_timetype'] == "due") {
                    $q->when(isset($requestdata['from']) && $requestdata['from'] != "", function ($q) use ($requestdata) {
                        return $q->where('due', '>=', $requestdata["from"]);
                    });

                    $q->when(isset($requestdata['to']) && $requestdata['to'] != "", function ($q) use ($requestdata) {
                        $to = $requestdata["to"] . " 23:59:00";
                        return $q->where('due', '<=', $to);
                    });

                    return $q->orderBy('due', 'desc');
                }

                return $q;
            });

            $query->when(isset($requestdata['job_type']) && $requestdata['job_type'] != '', function ($q) use ($requestdata) {
                return $q->whereIn('job_type', $requestdata['job_type']);
            });

            $query->when(isset($requestdata['physical']), function ($q) use ($requestdata) {
                return $q->where('customer_physical_type', $requestdata['physical'])->where('ignore_physical', 0);
            });

            $query->when(isset($requestdata['phone']), function ($q) use ($requestdata) {
                $q->where('customer_phone_type', $requestdata['phone']);
                if (isset($requestdata['physical'])) {
                    $q->where('ignore_physical_phone', 0);
                }
            });

            $query->when(isset($requestdata['flagged']), function ($q) use ($requestdata) {
                return $q->where('flagged', $requestdata['flagged'])->where('ignore_flagged', 0);
            });

            $query->when(isset($requestdata['distance']) && $requestdata['distance'] == 'empty', function ($q) {
                return $q->whereDoesntHave('distance');
            });

            $query->when(isset($requestdata['salary']) && $requestdata['salary'] == 'yes', function ($q) {
                return $q->whereDoesntHave('user.salaries');
            });

            $query->when(isset($requestdata['count']) && $requestdata['count'] == 'true', function ($q) {
                return ['count' => $q->count()];
            });

            $query->when(isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '', function ($q) use ($requestdata) {
                return $q->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            });

            $query->when(isset($requestdata['booking_type']), function ($q) use ($requestdata) {
                if ($requestdata['booking_type'] == 'physical') {
                    $q->where('customer_physical_type', 'yes');
                }

                if ($requestdata['booking_type'] == 'phone') {
                    $q->where('customer_phone_type', 'yes');
                }

                return $q;
            });

            $query->orderBy('created_at', 'desc');
            $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            if ($limit == 'all') {
                $allJobs = $query->get();
            } else {
                $allJobs = $query->paginate(15);
            }
        } else {
            $query->when(isset($requestdata['id']) && $requestdata['id'] != '', function ($q) use ($requestdata) {
                return $q->where('id', $requestdata['id']);
            });

            $query->when($consumerType == 'RWS', function ($q) {
                return $q->where('job_type', 'rws');
            }, function ($q) {
                return $q->where('job_type', 'unpaid');
            });

            $query->when(isset($requestdata['feedback']) && $requestdata['feedback'] != 'false', function ($q) {
                return $q->where('ignore_feedback', 0)->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', 3);
                });
            });

            $query->when(isset($requestdata['lang']) && $requestdata['lang'] != '', function ($q) use ($requestdata) {
                return $q->whereIn('from_language_id', $requestdata['lang']);
            });

            $query->when(isset($requestdata['status']) && $requestdata['status'] != '', function ($q) use ($requestdata) {
                return $q->whereIn('status', $requestdata['status']);
            });

            $query->when(isset($requestdata['job_type']) && $requestdata['job_type'] != '', function ($q) use ($requestdata) {
                return $q->whereIn('job_type', $requestdata['job_type']);
            });

            $query->when(isset($requestdata['customer_email']) && $requestdata['customer_email'] != '', function ($q) use ($requestdata) {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    return $q->where('user_id', $user->id);
                }
            });

            $query->when(isset($requestdata['filter_timetype']), function ($q) use ($requestdata) {
                if ($requestdata['filter_timetype'] == "created") {
                    $q->when(isset($requestdata['from']) && $requestdata['from'] != "", function ($q) use ($requestdata) {
                        return $q->where('created_at', '>=', $requestdata["from"]);
                    });

                    $q->when(isset($requestdata['to']) && $requestdata['to'] != "", function ($q) use ($requestdata) {
                        $to = $requestdata["to"] . " 23:59:00";
                        return $q->where('created_at', '<=', $to);
                    });

                    return $q->orderBy('created_at', 'desc');
                }

                if ($requestdata['filter_timetype'] == "due") {
                    $q->when(isset($requestdata['from']) && $requestdata['from'] != "", function ($q) use ($requestdata) {
                        return $q->where('due', '>=', $requestdata["from"]);
                    });

                    $q->when(isset($requestdata['to']) && $requestdata['to'] != "", function ($q) use ($requestdata) {
                        $to = $requestdata["to"] . " 23:59:00";
                        return $q->where('due', '<=', $to);
                    });

                    return $q->orderBy('due', 'desc');
                }

                return $q;
            });

            $query->orderBy('created_at', 'desc');
            $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            if ($limit == 'all') {
                $allJobs = $query->get();
            } else {
                $allJobs = $query->paginate(15);
            }
        }

        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);

            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }

                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $requestdata = Request::all();
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $query = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->whereIn('jobs.id', $jobId);

        $query->where('jobs.ignore', 0);

        if ($cuser && $cuser->is('superadmin')) {
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $query->whereIn('jobs.from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $query->whereIn('jobs.status', $requestdata['status']);
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $query->where('jobs.user_id', $user->id);
                }
            }

            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $query->whereIn('jobs.id', $allJobIDs);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $query->where('jobs.created_at', '>=', $requestdata["from"]);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $query->where('jobs.created_at', '<=', $to);
                }

                $query->orderBy('jobs.created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $query->where('jobs.due', '>=', $requestdata["from"]);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $query->where('jobs.due', '<=', $to);
                }

                $query->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $query->whereIn('jobs.job_type', $requestdata['job_type']);
            }

            $query->select('jobs.*', 'languages.language');
            $query->orderBy('jobs.created_at', 'desc');
            $allJobs = $query->paginate(15);
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function getPendingJobsWithExpiry()
    {
        // Retrieve active languages
        $languages = Language::where('active', '1')->orderBy('language')->get();

        // Retrieve request data
        $requestdata = Request::all();

        // Get all customer and translator emails
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        // Get the currently authenticated user and their consumer type
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        // Check if the user is a superadmin or admin
        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            // Query for pending jobs with expiry conditions
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }

            // Continue adding other conditions as needed...

            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata,
        ];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();

        return response()->json(['status' => 'success', 'message' => 'Changes saved']);
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();

        return response()->json(['status' => 'success', 'message' => 'Changes saved']);
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();

        return response()->json(['status' => 'success', 'message' => 'Changes saved']);
    }


    public function reopen(Request $request)
    {
        $jobid = $request->input('jobid');
        $userid = $request->input('userid');

        $job = Job::find($jobid);

        if (!$job) {
            return ["Job not found"];
        }

        $data = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job->due, now()),
            'updated_at' => now(),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => now(),
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job->due, now()),
        ];

        if ($job->status !== 'timedout') {
            Job::where('id', $jobid)->update($datareopen);
        } else {
            $jobArray = $job->toArray();
            $jobArray['status'] = 'pending';
            $jobArray['created_at'] = now();
            $jobArray['updated_at'] = now();
            $jobArray['will_expire_at'] = TeHelper::willExpireAt($job->due, now());
            $jobArray['cust_16_hour_email'] = 0;
            $jobArray['cust_48_hour_email'] = 0;
            $jobArray['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;

            Job::create($jobArray);
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => now()]);
        Translator::create($data);

        $this->sendNotificationByAdminCancelJob($jobid);

        return ["Job reopened successfully"];
    }

    private function convertToHoursMins($time)
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf('%02dh %02dmin', $hours, $minutes);
    }


}