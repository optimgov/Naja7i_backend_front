<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Models\Source;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Le formulaire d'une source — lot A4.
 *
 * DEUX CHOSES Y SONT DITES PLUTÔT QUE SUBIES.
 *
 * L'ÉTAT DE VÉRIFICATION D'ABORD, en haut, avec son auteur et sa date. C'est la
 * seule information qui décide si les questions citant cette source pourront
 * servir au diagnostic ; l'enfouir parmi douze champs de saisie reviendrait à
 * la cacher.
 *
 * CE QUE LA MODIFICATION VA COÛTER ENSUITE. Depuis le PAS-29, toucher une
 * colonne porteuse de sens ANNULE la vérification et rétrograde les citations
 * non gelées. Le formulaire sépare donc physiquement ces colonnes des autres et
 * le dit dans la description de la section — avant l'enregistrement, pas après.
 *
 * CE QUI EST ABSENT L'EST PAR HONNÊTETÉ. `verified_at` et `verified_by` ne sont
 * pas saisissables : ils sont hors de `$fillable`, et le seul chemin qui les
 * écrit est `SourceVerificationService`. Les colonnes de la cartographie du
 * corpus — `component`, `transposition_status`, `track_id` — n'y sont pas non
 * plus, et pour la même raison : hors de `$fillable`, un champ affiché ici
 * serait silencieusement ignoré à l'enregistrement. Un formulaire qui accepte
 * une saisie qu'il jette est pire que pas de champ du tout.
 */
class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::etat(),
            self::identification(),
            self::reperes(),
        ]);
    }

    /**
     * L'ÉTAT DE VÉRIFICATION, TOUJOURS À JOUR — y compris après une
     * modification qui vient de l'annuler.
     *
     * C'est ici que `SourceObserver` gagne sa place : le déclencheur qui annule
     * la vérification est un `BEFORE UPDATE`, il change la ligne écrite et non
     * l'instance PHP qui l'écrit. Sans l'observateur qui relit les deux
     * colonnes, cet encart continuerait d'afficher « vérifiée » après un
     * enregistrement qui vient de la révoquer, jusqu'à ce que quelqu'un
     * recharge la page. Le supprimer ne casse aucune garantie — seulement la
     * vérité de cet écran, ce qui suffit à en faire un défaut.
     */
    private static function etat(): Callout
    {
        return Callout::make()
            ->visible(fn (?Model $record) => $record instanceof Source)
            ->status(fn (?Model $record) => $record?->verified_at !== null ? 'success' : 'warning')
            ->heading(fn (?Model $record) => $record?->verified_at !== null
                ? 'Source vérifiée'
                : 'Source non vérifiée')
            ->schema([
                Html::make(fn (?Model $record) => e(self::etatDetaille($record))),
            ]);
    }

    private static function etatDetaille(?Source $source): string
    {
        if ($source?->verified_at === null) {
            return 'Aucun contrôle documentaire enregistré. Les questions qui citent cette '
                .'source restent publiables pour l\'entraînement, mais elles ne peuvent pas '
                .'servir au diagnostic. La vérification se déclenche depuis la liste des sources.';
        }

        /* QUI ET QUAND, nommément. « Vérifiée » sans signataire n'engage
         * personne, et un contrôle documentaire que personne n'a signé n'est
         * pas un contrôle. */
        return 'Contrôlée par '.($source->verificateur?->email ?? 'un compte supprimé')
            .' le '.$source->verified_at->translatedFormat('j F Y à H:i').'. '
            .'Les questions qui la citent peuvent servir au diagnostic.';
    }

    private static function identification(): Section
    {
        return Section::make('Identification')
            ->description(
                'Modifier l\'un de ces champs ANNULE la vérification et rétrograde les '
                .'citations des questions non publiées : ce sont eux qui désignent le '
                .'document, et un document renommé n\'est plus celui qu\'on a contrôlé. '
                .'Les repères de lecture, en dessous, ne coûtent rien.'
            )
            ->columns(2)
            ->schema([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->placeholder('SRC-CRMEF-2025-SE')
                    ->helperText('Identifiant stable, cité dans les blueprints.'),

                Select::make('kind')
                    ->label('Nature')
                    ->options(self::natures())
                    ->default('autre')
                    ->required()
                    ->helperText('Un descriptif officiel établit le PÉRIMÈTRE, jamais la bonne réponse.'),

                TextInput::make('title_fr')->label('Titre (français)')->required(),
                TextInput::make('title_ar')->label('Titre (arabe)'),

                TextInput::make('authority_fr')->label('Autorité (français)'),
                TextInput::make('authority_ar')->label('Autorité (arabe)'),

                TextInput::make('session_label')
                    ->label('Session')
                    ->placeholder('Novembre 2025'),

                TextInput::make('url')
                    ->label('URL')
                    ->url()
                    ->helperText('Le lien change quand le document change : c\'est pourquoi il annule la vérification.'),
            ]);
    }

    private static function reperes(): Section
    {
        return Section::make('Repères de lecture')
            ->description('Sans effet sur la vérification : ils précisent où lire, pas quel document lire.')
            ->columns(2)
            ->schema([
                Select::make('languages')
                    ->label('Langues du document')
                    ->multiple()
                    ->options(['fr' => 'Français', 'ar' => 'العربية']),

                Textarea::make('location_note_fr')
                    ->label('Repère (français)')
                    ->rows(2)
                    ->placeholder('Pages 2-3 : domaines et poids'),

                Textarea::make('location_note_ar')
                    ->label('Repère (arabe)')
                    ->rows(2),
            ]);
    }

    /** @return array<string, string> */
    private static function natures(): array
    {
        return [
            'descriptif_officiel' => 'Descriptif officiel',
            'texte_reglementaire' => 'Texte réglementaire',
            'ouvrage' => 'Ouvrage',
            'annale' => 'Annale',
            'autre' => 'Autre',
        ];
    }
}
