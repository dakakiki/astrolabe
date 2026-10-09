<?php

namespace App\Enums;

/**
 * What the audit log records (docs/spec/06, "audit podaci za kritične operacije").
 * A closed list: the frontend has a label for each, and nothing else is written.
 */
enum AuditEvent: string
{
    // The account itself; the person sees these in Settings → Security.
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Lockout = 'lockout';
    case Logout = 'logout';
    case Registered = 'registered';
    case EmailVerified = 'email_verified';
    case EmailChanged = 'email_changed';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case OtherSessionsSignedOut = 'other_sessions_signed_out';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorFailed = 'two_factor_failed';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case RecoveryCodeUsed = 'recovery_code_used';
    // Terms, DPA or privacy policy versions accepted or seen (Phase 8c).
    case LegalAccepted = 'legal_accepted';

    // Closed beta: invitations to register (the operator, from the command line).
    case InvitationSent = 'invitation_sent';
    case InvitationRevoked = 'invitation_revoked';
    case InvitationAccepted = 'invitation_accepted';

    // The practice's data.
    case PaymentsExported = 'payments_exported';
    case FileDownloaded = 'file_downloaded';
    case RecordDeleted = 'record_deleted';
    case PracticeSettingsChanged = 'practice_settings_changed';

    // The data lifecycle (Phase 8b).
    case ClientErased = 'client_erased';
    case PracticeExportRequested = 'practice_export_requested';
    case PracticeExportDownloaded = 'practice_export_downloaded';
    case PracticeDeletionRequested = 'practice_deletion_requested';
    case PracticeDeletionCancelled = 'practice_deletion_cancelled';
    case PracticeErased = 'practice_erased';
    case RetentionApplied = 'retention_applied';
    case BackupRestored = 'backup_restored';

    // The operator's admin (Phase 8c): what the admin looked at and did.
    case AdminCreated = 'admin_created';
    case AdminViewed = 'admin_viewed';
    case AdminTwoFactorReset = 'admin_two_factor_reset';
    case AdminVerificationResent = 'admin_verification_resent';
    case AccountSuspended = 'account_suspended';
    case AccountRestored = 'account_restored';
    case AdminJobRetried = 'admin_job_retried';
    case AdminJobDeleted = 'admin_job_deleted';
    case FeedbackSent = 'feedback_sent';

    /**
     * Events that may mean someone else is trying an account; the admin's audit
     * log shows them on their own and highlights them (Phase 8c).
     *
     * @return list<self>
     */
    public static function warnings(): array
    {
        return [
            self::LoginFailed,
            self::Lockout,
            self::TwoFactorFailed,
            self::RecoveryCodeUsed,
        ];
    }
}
