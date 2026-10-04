<?php
// SEATS-4: extra human team members bought on Pro and Agency ($20 a month each)
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            if (! Schema::hasColumn('subscriptions', 'extra_seats')) $t->unsignedSmallInteger('extra_seats')->default(0)->after('chatbot_addon_item_id');
            if (! Schema::hasColumn('subscriptions', 'extra_seats_item_id')) $t->string('extra_seats_item_id', 64)->nullable()->after('extra_seats');
        });
    }
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) { $t->dropColumn(['extra_seats', 'extra_seats_item_id']); });
    }
};