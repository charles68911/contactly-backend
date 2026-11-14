<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the obsolete email-authorization token table. Authenticated
     * password changes now use the current password directly, so no emailed
     * change tokens are created or stored.
     */
    public function up(): void
    {
        Schema::dropIfExists('password_change_requests');
    }

    /**
     * The removed email-authorization flow was intentionally retired, so the
     * table is not recreated on rollback.
     */
    public function down(): void
    {
        //
    }
};
