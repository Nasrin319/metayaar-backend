<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountType;
use App\Models\Concerns\HasAvatar;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'account_type'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasAvatar, HasFactory, HasRoles, Notifiable;

    /** What a disabled account is told when it tries to sign in or use the API. */
    public const string DISABLED_MESSAGE = 'حساب کاربری شما غیرفعال شده است. برای پیگیری با پشتیبانی متایار تماس بگیرید.';

    /**
     * The guard spatie/laravel-permission stores roles under, shared by session and Sanctum token auth.
     */
    protected string $guard_name = 'web';

    protected function avatarDirectory(): string
    {
        return "avatars/{$this->id}";
    }

    /**
     * Details of an organization account; null for individuals.
     *
     * @return HasOne<Organization, $this>
     */
    public function organization(): HasOne
    {
        return $this->hasOne(Organization::class);
    }

    /**
     * This developer's requests to be paid from their wallet.
     *
     * @return HasMany<PayoutRequest, $this>
     */
    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequest::class);
    }

    /**
     * Where this developer's sales are paid out; null until saved.
     *
     * @return HasOne<PayoutAccount, $this>
     */
    public function payoutAccount(): HasOne
    {
        return $this->hasOne(PayoutAccount::class);
    }

    public function isOrganization(): bool
    {
        return $this->account_type === AccountType::Organization;
    }

    /**
     * Listings this user publishes as a developer.
     *
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * APKs this user downloaded.
     *
     * @return HasMany<Download, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    /**
     * Paid listings this user owns.
     *
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * Changes to this developer's wallet balance (wallet_balance), newest last.
     *
     * @return HasMany<WalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Staff disabled the account in the admin panel: it cannot sign in or use the API.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Turn the account off and sign it out everywhere (website, desktop app, headset app).
     */
    public function disable(): void
    {
        $this->forceFill(['disabled_at' => now()])->save();
        $this->tokens()->delete();
    }

    public function enable(): void
    {
        $this->forceFill(['disabled_at' => null])->save();
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function hasPurchased(Listing $listing): bool
    {
        return $this->purchases()->whereBelongsTo($listing)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'wallet_balance' => 'integer',
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
