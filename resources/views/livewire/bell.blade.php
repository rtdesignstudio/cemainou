{{-- Clopoțelul (App\Livewire\Bell): vederea Filament, cu ultimele 10 și, jos, toată istoria și setările. Se închide și la clic în afara lui (n-are formular de pierdut). --}}
@php
    use Filament\Notifications\View\NotificationsIconAlias;
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\Contracts\Pagination\Paginator;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div class="fi-no-database">
    {{-- The focus trap autofocuses the modal window itself when the slide-over opens, since the first tabbable element is the `Mark all as read` header action, which `Enter` would otherwise immediately (and irreversibly) trigger. The window must carry the `autofocus` attribute because the focus trap resolves it once, when the modal first initializes, and the window is always rendered. --}}
    <x-filament::modal
        :alignment="$hasNotifications ? null : Alignment::Center"
        aria-labelledby="database-notifications.heading"
        close-button
        :close-by-clicking-away="true"
        :description="$hasNotifications ? null : __('filament-notifications::database.modal.empty.description')"
        :extra-modal-window-attribute-bag="
            new FilamentComponentAttributeBag([
                'autofocus' => true,
                'tabindex' => '-1',
            ])
        "
        :heading="$hasNotifications ? null : __('filament-notifications::database.modal.empty.heading')"
        :icon="$hasNotifications ? null : Heroicon::OutlinedBellSlash"
        :icon-alias="
            $hasNotifications
            ? null
            : NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE
        "
        :icon-color="$hasNotifications ? null : 'gray'"
        id="database-notifications"
        slide-over
        :sticky-header="$hasNotifications"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new FilamentComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if ($hasNotifications)
            <x-slot name="header">
                <div>
                    <h2
                        id="database-notifications.heading"
                        class="fi-modal-heading"
                    >
                        {{ __('filament-notifications::database.modal.heading') }}

                        @if ($unreadNotificationsCount)
                            <span
                                {{
                                    (new FilamentComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                        'fi-badge fi-size-xs',
                                    ])
                                }}
                            >
                                {{ $unreadNotificationsCount }}
                            </span>
                        @endif
                    </h2>

                    <div class="fi-ac">
                        @if ($unreadNotificationsCount && $this->markAllNotificationsAsReadAction?->isVisible())
                            {{ $this->markAllNotificationsAsReadAction }}
                        @endif

                        @if ($this->clearNotificationsAction?->isVisible())
                            {{ $this->clearNotificationsAction }}
                        @endif
                    </div>
                </div>
            </x-slot>

            <div
                aria-label="{{ __('filament-notifications::database.modal.heading') }}"
                role="list"
                class="fi-no-notifications"
            >
                @foreach ($notifications as $notification)
                    <div
                        role="listitem"
                        wire:key="{{ $notification->getKey() }}.database-notifications.ctn"
                        @class([
                            'fi-no-notification-read-ctn' => ! $notification->unread(),
                            'fi-no-notification-unread-ctn' => $notification->unread(),
                        ])
                    >
                        @if ($notification->unread())
                            <span class="fi-sr-only">
                                {{ __('filament-notifications::database.modal.unread_label') }}
                            </span>
                        @endif

                        {{ $this->getNotification($notification)->inline() }}
                    </div>
                @endforeach
            </div>

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => {
                                    setTimeout(
                                        () => $wire.call('$refresh'),
                                        500,
                                    )
                                },
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            <x-slot name="footer">
                <div class="wsa-bell-foot">
                    <a href="{{ \App\Filament\Pages\Notifications::getUrl() }}" class="wsa-bell-all">{{ __('All notifications') }}<x-wsa.arrow /></a>
                    <a href="{{ filament()->getProfileUrl() }}#notifications" class="wsa-meta">{{ __('What I receive') }}</a>
                </div>
            </x-slot>
        @endif
    </x-filament::modal>
</div>
