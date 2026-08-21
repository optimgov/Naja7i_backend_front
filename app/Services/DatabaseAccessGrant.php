<?php

namespace App\Services;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Implémentation par octrois explicites.
 *
 * Tant qu'aucun paiement n'existe, les octrois viennent d'une saisie manuelle
 * ou d'un organisme. Brancher CMI ajoutera une origine, pas une branche de
 * code ici.
 *
 * La capacité est vérifiée AU MOMENT DE L'USAGE, jamais mise en cache dans la
 * session : un abonnement qui expire pendant une session doit prendre effet
 * immédiatement.
 */
final class DatabaseAccessGrant implements AccessGrant
{
    /** @var array<string, list<array{0: string|null, 1: string|null}>> */
    private array $scopeChains = [];

    public function allows(
        User $user,
        string $capability,
        ?string $scopeType = null,
        ?string $scopeUuid = null,
    ): bool {
        $chain = $this->scopeChain($scopeType, $scopeUuid);

        if ($chain === []) {
            return false;
        }

        return AccessGrantRecord::where('user_id', $user->id)
            ->where('capability', $capability)
            ->active()
            ->where(function (Builder $query) use ($chain) {
                foreach ($chain as [$type, $uuid]) {
                    $query->orWhere(function (Builder $scope) use ($type, $uuid) {
                        if ($type === null) {
                            $scope->whereNull('scope_type')->whereNull('scope_uuid');

                            return;
                        }

                        $scope->where('scope_type', $type)->where('scope_uuid', $uuid);
                    });
                }
            })
            ->exists();
    }

    /** @return list<string> */
    public function capabilities(User $user): array
    {
        return AccessGrantRecord::where('user_id', $user->id)
            ->active()
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    private function scopeChain(?string $scopeType, ?string $scopeUuid): array
    {
        if (($scopeType === null) !== ($scopeUuid === null)) {
            return [];
        }

        if ($scopeType === null) {
            return [[null, null]];
        }

        if (! in_array($scopeType, AccessGrantRecord::SCOPE_TYPES, true)) {
            return [];
        }

        $key = $scopeType.':'.$scopeUuid;

        return $this->scopeChains[$key] ??= match ($scopeType) {
            AccessGrantRecord::SCOPE_AUDIENCE => [
                [AccessGrantRecord::SCOPE_AUDIENCE, $scopeUuid],
                [null, null],
            ],
            AccessGrantRecord::SCOPE_FILIERE => $this->filiereChain($scopeUuid),
            AccessGrantRecord::SCOPE_EXAM_FAMILY => $this->examFamilyChain($scopeUuid),
            AccessGrantRecord::SCOPE_EXAM => $this->examChain($scopeUuid),
            AccessGrantRecord::SCOPE_COMPETENCY_NODE => $this->competencyNodeChain($scopeUuid),
        };
    }

    /** @return list<array{0: string|null, 1: string|null}> */
    private function filiereChain(string $uuid): array
    {
        $filiere = DB::table('filieres')->where('uuid', $uuid)->value('uuid');

        return $filiere === null
            ? []
            : [[AccessGrantRecord::SCOPE_FILIERE, $filiere], [null, null]];
    }

    /** @return list<array{0: string|null, 1: string|null}> */
    private function examFamilyChain(string $uuid): array
    {
        $family = DB::table('exam_families as family')
            ->join('filieres as filiere', 'filiere.id', '=', 'family.filiere_id')
            ->where('family.uuid', $uuid)
            ->select('family.uuid as family_uuid', 'filiere.uuid as filiere_uuid')
            ->first();

        if ($family === null) {
            return [];
        }

        return [
            [AccessGrantRecord::SCOPE_EXAM_FAMILY, $family->family_uuid],
            [AccessGrantRecord::SCOPE_FILIERE, $family->filiere_uuid],
            [null, null],
        ];
    }

    /** @return list<array{0: string|null, 1: string|null}> */
    private function examChain(string $uuid): array
    {
        $exam = DB::table('exams as exam')
            ->join('tracks as track', 'track.id', '=', 'exam.track_id')
            ->join('exam_families as family', 'family.id', '=', 'track.exam_family_id')
            ->join('filieres as filiere', 'filiere.id', '=', 'family.filiere_id')
            ->where('exam.uuid', $uuid)
            ->select(
                'exam.uuid as exam_uuid',
                'family.uuid as family_uuid',
                'filiere.uuid as filiere_uuid',
            )
            ->first();

        if ($exam === null) {
            return [];
        }

        return [
            [AccessGrantRecord::SCOPE_EXAM, $exam->exam_uuid],
            [AccessGrantRecord::SCOPE_EXAM_FAMILY, $exam->family_uuid],
            [AccessGrantRecord::SCOPE_FILIERE, $exam->filiere_uuid],
            [null, null],
        ];
    }

    /** @return list<array{0: string|null, 1: string|null}> */
    private function competencyNodeChain(string $uuid): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT ancestor.uuid AS node_uuid,
                   exam.uuid AS exam_uuid,
                   family.uuid AS family_uuid,
                   filiere.uuid AS filiere_uuid
            FROM competency_nodes AS requested
            JOIN LATERAL unnest(string_to_array(requested.path, '.')::bigint[])
                 WITH ORDINALITY AS path_node(id, position) ON TRUE
            JOIN competency_nodes AS ancestor ON ancestor.id = path_node.id
            LEFT JOIN exams AS exam ON exam.id = requested.exam_id
            LEFT JOIN tracks AS track ON track.id = exam.track_id
            LEFT JOIN exam_families AS family
                   ON family.id = COALESCE(track.exam_family_id, requested.exam_family_id)
            LEFT JOIN filieres AS filiere ON filiere.id = family.filiere_id
            WHERE requested.uuid = ?
            ORDER BY path_node.position DESC
            SQL, [$uuid]);

        if ($rows === []) {
            return [];
        }

        $chain = [];

        foreach ($rows as $row) {
            $chain[] = [AccessGrantRecord::SCOPE_COMPETENCY_NODE, $row->node_uuid];
        }

        $first = $rows[0];

        if ($first->exam_uuid !== null) {
            $chain[] = [AccessGrantRecord::SCOPE_EXAM, $first->exam_uuid];
        }

        if ($first->family_uuid !== null) {
            $chain[] = [AccessGrantRecord::SCOPE_EXAM_FAMILY, $first->family_uuid];
        }

        if ($first->filiere_uuid !== null) {
            $chain[] = [AccessGrantRecord::SCOPE_FILIERE, $first->filiere_uuid];
        }

        $chain[] = [null, null];

        return $chain;
    }
}
