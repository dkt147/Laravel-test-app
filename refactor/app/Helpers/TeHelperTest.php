<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\TeHelper;

class TeHelperTest extends TestCase
{
    public function testWillExpireAt()
    {
        // Mock or set up any dependencies if needed

        // Example usage of the willExpireAt method
        $dueDate = '2023-10-15 14:30:00';
        $currentTime = '2023-10-14 12:00:00';

        $result = TeHelper::willExpireAt($dueDate, $currentTime);

        // Add your assertion to check the result
        $this->assertEquals('2023-10-15 16:30:00', $result);
    }
}
