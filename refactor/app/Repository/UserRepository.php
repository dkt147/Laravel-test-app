<?php

namespace App\Repository;

use App\User;

class UserRepository
{
    public function createOrUpdate(array $data)
    {
        // Logic to create or update a user
        // You can customize this method according to your requirements

        // Example: Try to find a user by email
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            // If the user already exists, update the data
            $user->update($data);
        } else {
            // If the user doesn't exist, create a new user
            $user = User::create($data);
        }

        return $user;
    }
}
