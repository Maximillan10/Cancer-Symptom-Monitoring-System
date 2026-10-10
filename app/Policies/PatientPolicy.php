<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use App\Support\EditorAccess;

/**
 * Reading: a clinician can open patients assigned to them, admins can open every patient, and when the
 * shared dashboard is on (EDITOR_EMAILS set) every signed-in clinician can open every patient.
 * Changing: on the shared dashboard only the listed editors may change a patient.
 * Laravel discovers this policy automatically (App\Policies\PatientPolicy for App\Models\Patient).
 */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // the controller scopes the list
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->isAdmin()
            || EditorAccess::sharedView()
            || (int) $patient->assigned_clinician_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Patient $patient): bool
    {
        if (EditorAccess::sharedView()) {
            return EditorAccess::can($user);
        }

        return $this->view($user, $patient);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $this->update($user, $patient);
    }
}
