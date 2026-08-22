<?php

namespace App\Services;

use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\ExamFamily;
use App\Models\Filiere;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * La portée d'une offre : un type fermé, un objet qui existe vraiment.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE GARDE SERVEUR, ALORS QUE L'ÉCRAN PROPOSE UNE LISTE
 *
 * Parce qu'une requête forgée ne passe pas par l'écran. La spécification est
 * explicite : « Chaque interdit est doublé d'un refus côté serveur, jamais d'un
 * simple masquage d'interface. » Le type est tenu par l'énumération
 * PostgreSQL ; ce qui reste à vérifier ici est ce qu'une énumération ne sait
 * pas dire — que l'objet DÉSIGNÉ existe, qu'il est du bon type, et qu'il n'est
 * pas retiré du catalogue.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * « NON RETIRÉ » N'EST PAS « PUBLIÉ »
 *
 * Une offre se prépare avant l'ouverture du concours qu'elle vise : exiger
 * `published` interdirait de composer un pack sur une épreuve dont la fiche
 * n'est pas encore publique, ce qui est précisément le travail d'une équipe
 * commerciale. On refuse donc l'ARCHIVÉ — ce qui a été retiré ne se vend plus —
 * et on laisse passer le brouillon.
 */
final class PorteeVendable
{
    /** Le refus nomme l'objet manquant : un refus muet se lit comme une panne. */
    public function assertDesignable(?string $type, ?string $uuid): void
    {
        if ($type === null && $uuid === null) {
            return;
        }

        if ($type === null || $uuid === null) {
            throw ValidationException::withMessages([
                'scope_type' => 'Une portée est un couple : un type et l’objet qu’il désigne, '
                    .'ou rien du tout — la portée nulle vaut la plateforme entière.',
            ]);
        }

        if (! in_array($type, AccessGrantRecord::SCOPE_TYPES, true)) {
            throw ValidationException::withMessages([
                'scope_type' => "Le type de portée « {$type} » n’existe pas : "
                    .'la liste est fermée en code, et un type sans règle d’ascendance ne se résout pas.',
            ]);
        }

        if ($this->existe($type, $uuid)) {
            return;
        }

        throw ValidationException::withMessages([
            'scope_uuid' => "Aucun objet de type « {$type} » ne correspond à cette portée, "
                .'ou il a été retiré du catalogue.',
        ]);
    }

    /**
     * Ce que l'écran propose pour un type donné — libellé localisé.
     *
     * @return array<string, string>
     */
    public function optionsPour(?string $type, ?string $locale = null): array
    {
        if ($type === null || ! in_array($type, AccessGrantRecord::SCOPE_TYPES, true)) {
            return [];
        }

        if ($type === AccessGrantRecord::SCOPE_AUDIENCE) {
            return Audience::query()->active()->ordered()->get()
                ->mapWithKeys(fn (Audience $a): array => [$a->uuid => $a->localized('name', $locale)])
                ->all();
        }

        if ($type === AccessGrantRecord::SCOPE_COMPETENCY_NODE) {
            return CompetencyNode::query()->orderBy('path')->limit(500)->get()
                ->mapWithKeys(fn (CompetencyNode $n): array => [$n->uuid => $n->localized('name', $locale)])
                ->all();
        }

        return $this->requete($type)->get()
            ->mapWithKeys(fn ($objet): array => [$objet->uuid => $objet->localized('name', $locale)])
            ->all();
    }

    private function existe(string $type, string $uuid): bool
    {
        if ($type === AccessGrantRecord::SCOPE_AUDIENCE) {
            return Audience::query()->active()->where('uuid', $uuid)->exists();
        }

        if ($type === AccessGrantRecord::SCOPE_COMPETENCY_NODE) {
            return CompetencyNode::query()->where('uuid', $uuid)->exists();
        }

        return $this->requete($type)->where('uuid', $uuid)->exists();
    }

    private function requete(string $type): Builder
    {
        /** @var class-string<Model> $modele */
        $modele = match ($type) {
            AccessGrantRecord::SCOPE_FILIERE => Filiere::class,
            AccessGrantRecord::SCOPE_EXAM_FAMILY => ExamFamily::class,
            AccessGrantRecord::SCOPE_EXAM => Exam::class,
        };

        return $modele::query()->where('status', '<>', 'archived')->orderBy('position');
    }
}
