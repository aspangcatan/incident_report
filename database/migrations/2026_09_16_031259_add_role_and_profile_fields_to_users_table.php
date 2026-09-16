<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('staff')->after('email');
            $table->foreignId('department_id')->nullable()->after('role')->constrained('departments')->nullOnDelete();
            $table->string('designation')->nullable()->after('department_id');
            $table->string('employee_no')->nullable()->unique()->after('designation');
            $table->string('prc_license_no')->nullable()->after('employee_no');
            $table->boolean('is_active')->default(true)->after('prc_license_no');
            $table->string('external_user_id')->nullable()->unique()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'role', 'designation', 'employee_no', 'prc_license_no', 'is_active', 'external_user_id',
            ]);
        });
    }
};
