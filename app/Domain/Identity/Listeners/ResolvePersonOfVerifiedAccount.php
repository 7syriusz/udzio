<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Actions\ResolveAccountPerson;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

class ResolvePersonOfVerifiedAccount
{
    public function __construct(private readonly ResolveAccountPerson $resolve) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->resolve->handle($event->user);
        }
    }
}
