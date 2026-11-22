<?php

use App\Services\TitleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** The Ownership tab becomes the single place to set owners: copy quick-field owners into title records, then re-derive the fields. */
    public function up(): void
    {
        $sources = [
            'account' => ['accounts', ['person' => 'owner_person_id', 'entity' => 'owner_entity_id']],
            'property' => ['properties', ['entity' => 'entity_id']],
            'asset' => ['assets', ['entity' => 'entity_id']],
        ];
        foreach ($sources as $type => [$table, $cols]) {
            $rows = DB::table($table)->whereNull('archived_at')->where(function ($q) use ($cols) {
                foreach ($cols as $c) {
                    $q->orWhereNotNull($c);
                }
            })->get();
            foreach ($rows as $r) {
                $titled = DB::table('ownership_interests')->where('workspace_id', $r->workspace_id)->where('subject_type', $type)->where('subject_id', $r->id)
                    ->where('basis', 'legal_title')->whereNull('archived_at')->exists();
                if ($titled) {
                    continue;
                }
                [$ownerType, $ownerId] = isset($cols['person']) && $r->{$cols['person']} ? ['person', $r->{$cols['person']}] : ['entity', $r->{$cols['entity']}];
                DB::table('ownership_interests')->insert([
                    'id' => (string) Str::ulid(), 'workspace_id' => $r->workspace_id, 'scope_id' => $r->scope_id, 'owner_type' => $ownerType, 'owner_id' => $ownerId,
                    'subject_type' => $type, 'subject_id' => $r->id, 'basis' => 'legal_title', 'fraction' => '1', 'title_form' => $ownerType === 'person' ? 'sole' : 'entity',
                    'capacity' => 'individual', 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                ]);
            }
            $titled = DB::table('ownership_interests')->where('subject_type', $type)->where('basis', 'legal_title')->whereNull('archived_at')->distinct()->get(['workspace_id', 'subject_id']);
            foreach ($titled as $t) {
                TitleService::syncDerived($t->workspace_id, $type, $t->subject_id);
            }
        }
    }

    public function down(): void
    {
        // Data-only and additive: title records created here are kept.
    }
};
