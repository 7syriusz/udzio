<?php

namespace App\Domain\Platform\Concerns;

use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\DefinitionVersion;
use App\Domain\Platform\Versioning\ContentHash;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * Definition side of "definition → version → result" (A5-11, A5-16). The model is the editable draft;
 * publishVersion() freezes its snapshot as the next DefinitionVersion. Results reference the version.
 * The model must also use AuditsChanges (subject type and organization come from there).
 */
trait HasVersions
{
    /**
     * Snapshot of everything that decides how results are produced. Must be complete: a result is
     * interpreted only through this content.
     *
     * @return array<string, mixed>
     */
    abstract public function versionSnapshot(): array;

    public function versions(): HasMany
    {
        return $this->hasMany(DefinitionVersion::class, 'definition_id')
            ->where('definition_type', $this->auditSubjectType())
            ->orderBy('version');
    }

    /**
     * Freezes the current snapshot as a new version. Publishing unchanged content returns the latest
     * version instead of creating a duplicate.
     */
    public function publishVersion(): DefinitionVersion
    {
        if (! $this->exists || $this->isDirty()) {
            throw new LogicException('Save the definition before publishing a version.');
        }

        return $this->getConnection()->transaction(function (): DefinitionVersion {
            // Serializes publications of one definition; version numbers stay gapless and unique.
            $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $content = $this->versionSnapshot();
            $hash = ContentHash::of($content);
            $latest = $this->latestVersion();
            if ($latest?->content_hash === $hash) {
                return $latest;
            }

            $actor = app(ActorContext::class)->current();
            $version = new DefinitionVersion;
            $version->setConnection($this->getConnectionName());
            $version->fill([
                'public_id' => (string) Str::ulid(),
                'definition_type' => $this->auditSubjectType(),
                'definition_id' => $this->getKey(),
                'version' => ($latest?->version ?? 0) + 1,
                'content' => $content,
                'content_hash' => $hash,
                'published_by_type' => $actor->type,
                'published_by_id' => $actor->identifier,
                'published_at' => now('UTC'),
            ])->save();

            app(RecordAudit::class)->handle(
                $this->auditSubjectType().'.version_published',
                $this->auditSubjectType(),
                (string) $this->getKey(),
                organizationId: $this->auditOrganizationId(),
                reason: app(AuditReason::class)->current(),
                after: ['version' => $version->version, 'content_hash' => $hash],
            );

            return $version;
        });
    }

    public function latestVersion(): ?DefinitionVersion
    {
        return $this->versions()->reorder('version', 'desc')->first();
    }

    /** The version in force at a moment: the last one published up to that moment. */
    public function versionAt(DateTimeInterface $moment): ?DefinitionVersion
    {
        return $this->versions()->where('published_at', '<=', $moment->format('Y-m-d H:i:s.u'))
            ->reorder('version', 'desc')->first();
    }
}
