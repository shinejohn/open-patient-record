<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix for a confirmed-critical review finding: the vault's DERIVED tokens live
 * ~30 minutes, so a stored token alone bricks every chart read half an hour
 * after registration. The grant's redemption secret (pseudo_id was already
 * stored; the OTP was not) is what lets the EHR re-redeem. Storing the OTP
 * encrypted is the same security posture as storing the derived token — it is
 * the same capability, bounded by the grant's expiry, max_uses, and the
 * patient's revocation right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->text('grant_otp')->nullable(); // encrypted cast
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('grant_otp');
        });
    }
};
