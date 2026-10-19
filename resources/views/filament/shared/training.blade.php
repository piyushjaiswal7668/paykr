<x-filament-panels::page>
    <p>Choose a section to watch. Videos include English narration and captions and use demonstration data.</p>
    @forelse ($this->lessons() as $lesson)
        <details wire:key="training-{{ $lesson['audience'] }}-{{ $lesson['id'] }}" style="padding:16px;border:1px solid #94a3b8;border-radius:12px;margin-bottom:12px">
            <summary style="cursor:pointer;font-weight:600">{{ ucfirst($lesson['audience']) }} · {{ $lesson['title'] }}</summary>
            <video controls preload="none" playsinline style="width:100%;max-height:70vh;margin-top:16px" aria-label="{{ $lesson['title'] }}">
                <source src="{{ URL::temporarySignedRoute('training.video', now()->addHours(2), ['audience' => $lesson['audience'], 'id' => $lesson['id']]) }}" type="video/mp4">
                Your browser does not support video playback.
            </video>
            <p style="margin-top:8px">If playback access expires, refresh this page and reopen the section.</p>
        </details>
    @empty
        <p>Training videos have not been installed yet. Contact your platform administrator.</p>
    @endforelse
</x-filament-panels::page>
