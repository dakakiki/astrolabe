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

    // Closed beta: invitations to register (the operator, from the command line).
    case InvitationSent = 'invitation_sent';
    case InvitationRevoked = 'invitation_revoked';
    case InvitationAccepted = 'invitation_accepted';

    // The practice's data.
    case PaymentsExported = 'payments_exported';
    case FileDownloaded = 'file_downloaded';
    case RecordDeleted = 'record_deleted';
    case PracticeSettingsChanged = 'practice_settings_changed';

    /**
     * Events about the account, shown to its owner ("Recent security activity").
     *
     * @return list<self>
     */
    public static function account(): array
    {
        return [
            self::Login,
            self::LoginFailed,
            self::Lockout,
            self::Logout,
            self::Registered,
            self::EmailVerified,
            self::EmailChanged,
            self::PasswordChanged,
            self::PasswordReset,
            self::OtherSessionsSignedOut,
            self::TwoFactorEnabled,
            self::TwoFactorDisabled,
            self::TwoFactorFailed,
            self::RecoveryCodesRegenerated,
            self::RecoveryCodeUsed,
        ];
    }
}
