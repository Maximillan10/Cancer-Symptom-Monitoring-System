<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/**
 * A clinician can only access patients assigned to them.
 * Admins can access every patient.
 * Laravel discovers this policy automatically (App\Policies\PatientPolicy for App\Models\Patient).
 */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // the controller scopes the list to the clinician's own patients
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->isAdmin() || (int) $patient->assigned_clinician_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient);
    }
}
