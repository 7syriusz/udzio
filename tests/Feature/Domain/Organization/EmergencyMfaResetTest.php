<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Notifications\MfaResetNotice;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Actions\EmergencyResetAccountMfa;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * E3.8d (Z-040): emergency MFA reset from the server console — names one account, needs a reason, an identity
 * confirmation and an explicit confirmation; removes MFA, recovery codes and sessions, tells the holder, grants
 * nothing and is audited, a refused attempt too.
 */
class EmergencyMfaResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->artisan('platform:install-administrator', ['email' => 'admin@example.test', '--given-name' => 'Ada', '--family-name' => 'Nowak'])->assertSuccessful();
        $this->administrator = User::query()->sole();
        $this->administrator->markEmailAsVerified();
        $mfa = User::factory()->withTwoFactor()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        $this->app->make(AuditReason::class)->because('test: MFA set up', fn () => $this->administrator->forceFill($mfa)->save());
        $this->administrator->refresh();
    }

    /** @param array<string, string> $options */
    private function reset(array $options = []): int
    {
        return $this->artisan('platform:emergency-mfa-reset', [
            'email' => 'admin@example.test',
            '--reason' => 'Utrata telefonów przez obu administratorów',
            '--identity-confirmation' => 'Dowód osobisty sprawdzony osobiście w biurze',
            '--confirm' => 'admin@example.test',
            ...$options,
        ])->run();
    }

    private function decide(): string
    {
        return $this->app->make(PlatformAccess::class)->decide($this->administrator->fresh(), PlatformPermission::AdministratorsManage)->reason;
    }

    public function test_console_reset_removes_mfa_codes_and_sessions_and_tells_the_holder(): void
    {
        DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $this->administrator->id, 'payload' => '', 'last_activity' => time()]);
        $rememberToken = $this->administrator->remember_token;
        $this->assertSame('assignment', $this->decide());

        $this->artisan('platform:emergency-mfa-reset', [
            'email' => 'admin@example.test',
            '--reason' => 'Utrata telefonów przez obu administratorów',
            '--identity-confirmation' => 'Dowód osobisty sprawdzony osobiście w biurze',
            '--confirm' => 'admin@example.test',
        ])->expectsOutputToContain('Żadne uprawnienia nie zostaną nadane.')
            ->expectsOutputToContain('Zresetowano uwierzytelnianie dwuskładnikowe konta admin@example.test')
            ->assertSuccessful();

        $account = $this->administrator->fresh();
        $this->assertFalse($account->hasConfirmedTwoFactor());
        $this->assertNull($account->two_factor_recovery_codes);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $account->id)->count());
        $this->assertNotSame($rememberToken, $account->remember_token);
        Notification::assertSentTo($account, MfaResetNotice::class, function (MfaResetNotice $notice) use ($account): bool {
            $mail = $notice->toMail($account);

            return $mail->subject === 'Uwierzytelnianie dwuskładnikowe na Twoim koncie zostało zresetowane'
                && str_contains(implode(' ', $mail->introLines), 'ponownie włącz uwierzytelnianie dwuskładnikowe');
        });
    }

    public function test_reset_grants_nothing_and_platform_permissions_wait_for_new_mfa(): void
    {
        $counts = fn () => [User::query()->count(), PlatformRoleAssignment::query()->count(), RoleAssignment::query()->count()];
        $before = $counts();

        $this->assertSame(0, $this->reset());

        $this->assertSame($before, $counts(), 'Brak nowych kont, ról i przypisań.');
        $this->assertSame('mfa_required', $this->decide());
        $mfa = User::factory()->withTwoFactor()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        $this->app->make(AuditReason::class)->because('test: MFA set up again', fn () => $this->administrator->fresh()->forceFill($mfa)->save());
        $this->assertSame('assignment', $this->decide(), 'Po ponownym ustawieniu MFA uprawnienia wracają.');
    }

    public function test_reset_is_fully_audited(): void
    {
        $this->reset();

        $entry = AuditEntry::query()->where(['action' => 'account.mfa_reset', 'result' => 'succeeded'])->sole();
        $this->assertSame('process', $entry->actor_type->value);
        $this->assertSame((string) $this->administrator->id, $entry->subject_id);
        $this->assertSame('Utrata telefonów przez obu administratorów', $entry->reason);
        $this->assertSame('emergency', $entry->after_values['procedure']);
        $this->assertSame('Dowód osobisty sprawdzony osobiście w biurze', $entry->after_values['identity_confirmation']);
        $this->assertMatchesRegularExpression('/^.+@.+$/', $entry->after_values['operator']);
        $this->assertSame(['platform.emergency.mfa_reset'], AuditEntry::on('audit')->where('action', 'system_authority.entered')->latest('id')->first()->after_values['permissions']);
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'access.granted', 'subject_type' => 'account'])->count());
    }

    public function test_without_explicit_confirmation_nothing_changes_and_the_attempt_is_audited(): void
    {
        $this->assertSame(1, $this->reset(['--confirm' => 'inny@example.test']));
        $this->artisan('platform:emergency-mfa-reset', ['email' => 'admin@example.test', '--reason' => 'Powód', '--identity-confirmation' => 'Dokument', '--no-interaction' => true])
            ->expectsOutputToContain('Operacja nie została potwierdzona')
            ->assertFailed();
        $this->artisan('platform:emergency-mfa-reset', ['email' => 'admin@example.test', '--reason' => 'Powód', '--identity-confirmation' => 'Dokument'])
            ->expectsQuestion('Aby potwierdzić, wpisz ponownie adres e-mail konta', 'nie')
            ->assertFailed();

        $this->assertTrue($this->administrator->fresh()->hasConfirmedTwoFactor());
        Notification::assertNotSentTo($this->administrator, MfaResetNotice::class);
        $refusals = AuditEntry::on('audit')->where(['action' => 'account.mfa_reset', 'result' => 'denied'])->get();
        $this->assertCount(3, $refusals);
        $this->assertSame('not_confirmed', $refusals->first()->after_values['refusal']);
    }

    public function test_confirmed_interactively_by_typing_the_address_again(): void
    {
        $this->artisan('platform:emergency-mfa-reset', ['email' => 'admin@example.test'])
            ->expectsQuestion('Powód awaryjnego resetu', 'Utrata telefonu')
            ->expectsQuestion('Jak potwierdzono tożsamość właściciela konta?', 'Wideorozmowa i dokument')
            ->expectsQuestion('Aby potwierdzić, wpisz ponownie adres e-mail konta', 'admin@example.test')
            ->assertSuccessful();

        $this->assertFalse($this->administrator->fresh()->hasConfirmedTwoFactor());
    }

    public function test_reason_and_identity_confirmation_are_required(): void
    {
        $this->assertSame(1, $this->reset(['--reason' => ' ']));
        $this->assertSame(1, $this->reset(['--identity-confirmation' => '']));
        $this->assertSame(1, $this->artisan('platform:emergency-mfa-reset', ['email' => 'nikt@example.test', '--confirm' => 'nikt@example.test'])->run());

        $this->assertTrue($this->administrator->fresh()->hasConfirmedTwoFactor());
        $this->assertSame(0, AuditEntry::query()->where(['action' => 'account.mfa_reset', 'result' => 'succeeded'])->count());
    }

    public function test_no_account_can_use_the_emergency_procedure(): void
    {
        $this->assertSame('console_only', $this->decide() === 'assignment'
            ? $this->app->make(PlatformAccess::class)->decide($this->administrator, PlatformPermission::EmergencyMfaReset)->reason
            : 'unexpected');
        $holder = User::factory()->withTwoFactor()->create();

        try {
            $this->app->make(ActorContext::class)->runAs(Actor::account((string) $this->administrator->id),
                fn () => $this->app->make(EmergencyResetAccountMfa::class)->handle($holder, 'Dokument', 'Powód', 'operator@host'));
            $this->fail('Konto nie może użyć procedury awaryjnej.');
        } catch (AccessDenied) {
        }
        $this->assertTrue($holder->fresh()->hasConfirmedTwoFactor());
        $this->assertSame('console_only', AuditEntry::on('audit')->where('action', 'access.denied')->latest('id')->first()->after_values['reason']);
    }
}
