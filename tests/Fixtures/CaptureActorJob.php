<?php

namespace Tests\Fixtures;

use App\Domain\Platform\ActorContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class CaptureActorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $label, public bool $fail = false) {}

    public function handle(ActorContext $context, ActorProbe $probe): void
    {
        $probe->observations[$this->label] = $context->current()->toArray();

        if ($this->fail) {
            throw new RuntimeException('job failed');
        }
    }
}
