<?php

namespace App\Filament\Resources\ComplaintThreads\Pages;

use App\Filament\Resources\ComplaintThreads\ComplaintThreadResource;
use App\Models\ComplaintMessage;
use App\Models\ComplaintThread;
use App\Services\ComplaintService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class ViewComplaintThread extends ViewRecord
{
    protected static string $resource = ComplaintThreadResource::class;

    protected string $view = 'filament.resources.complaint-threads.pages.view-complaint-thread';

    public function getTitle(): string
    {
        return $this->getRecord()->subject;
    }

    public function getSubheading(): ?string
    {
        /** @var ComplaintThread $thread */
        $thread = $this->getRecord();

        return $thread->candidate->email.' · '.ComplaintThreadResource::categoryLabel($thread->category);
    }

    /** @return Collection<int, ComplaintMessage> */
    public function threadMessages(): Collection
    {
        /** @var ComplaintThread $thread */
        $thread = $this->getRecord();

        return $thread->messages()->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Répondre')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => auth()->user()?->can('reply', $this->getRecord()) ?? false)
                ->schema([
                    Textarea::make('body')
                        ->label('Réponse')
                        ->required()
                        ->maxLength(5000)
                        ->rows(6),
                    Hidden::make('idempotency_key')
                        ->default(fn (): string => (string) Str::uuid7())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var ComplaintThread $thread */
                    $thread = $this->getRecord();
                    $result = app(ComplaintService::class)->replyAsStaff(
                        auth()->user(),
                        $thread,
                        trim($data['body']),
                        $data['idempotency_key'],
                    );

                    $this->record = $result['thread'];
                    Notification::make()->success()->title('Réponse envoyée')->send();
                }),
        ];
    }
}
