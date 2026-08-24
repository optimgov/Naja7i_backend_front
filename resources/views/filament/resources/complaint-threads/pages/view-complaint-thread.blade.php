<x-filament-panels::page>
    <div class="space-y-4">
        @foreach ($this->threadMessages() as $message)
            <section @class([
                'rounded-xl border p-4 shadow-sm',
                'border-primary-200 bg-primary-50 dark:border-primary-800 dark:bg-primary-950' => $message->sender_type === 'staff',
                'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => $message->sender_type === 'candidate',
            ])>
                <div class="mb-2 flex items-center justify-between gap-4 text-sm">
                    <strong>{{ $message->sender_type === 'staff' ? 'Équipe Naja7i' : 'Candidat' }}</strong>
                    <time datetime="{{ $message->created_at->toIso8601String() }}">
                        {{ $message->created_at->format('d/m/Y H:i') }}
                    </time>
                </div>
                <p class="whitespace-pre-wrap" dir="auto">{{ $message->body }}</p>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
