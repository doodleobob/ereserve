@foreach($reservation->officialUseConflicts as $conflict)
    @if($conflict->resolution !== 'resolved' && $reservation->status === 'accepted')
        @if($compact ?? false)
            <div class="official-use-conflict-summary">
                <button type="button" class="official-use-conflict-link" data-reservation-open="view-{{ $reservation->id }}" aria-label="View Official Use conflict #{{ $conflict->official_use_id }} for reservation #{{ $reservation->id }}" aria-haspopup="dialog">Official Use Conflict</button>
                <small aria-label="Resolution: {{ $conflict->resolutionLabel() }}">{{ $conflict->resolutionLabel() }}</small>
            </div>
        @else
            <div class="official-use-conflict">
                <p><strong>Official Use Conflict #{{ $conflict->official_use_id }}</strong></p>
                <p class="official-use-conflict-resolution">Resolution: <strong>{{ $conflict->resolutionLabel() }}</strong></p>
                @php($conflictPeriod = $conflict->officialUse->period())
                <dl class="official-use-conflict-schedule">
                    <dt>Affected Schedule</dt>
                    <dd>
                        @if($conflictPeriod->start->isSameDay($conflictPeriod->end))
                            <span>{{ $conflictPeriod->start->format('F j, Y') }}</span>
                            <span>{{ $conflictPeriod->start->format('g:i A') }} &ndash; {{ $conflictPeriod->end->format('g:i A') }}</span>
                        @else
                            <span>Start: {{ $conflictPeriod->start->format('F j, Y') }} <span>{{ $conflictPeriod->start->format('g:i A') }}</span></span>
                            <span>End: {{ $conflictPeriod->end->format('F j, Y') }} <span>{{ $conflictPeriod->end->format('g:i A') }}</span></span>
                        @endif
                    </dd>
                </dl>
                @unless($isAdmin)
                    <p>Choose your preference. The barangay administrator will process the change.</p>
                    <div class="official-use-conflict-actions">
                        @foreach(['reschedule' => 'Request Reschedule', 'cancel' => 'Request Cancellation'] as $decision => $label)
                            <button type="button" class="{{ $decision === 'reschedule' ? 'facility-modal-primary' : 'facility-modal-secondary' }}" data-modal-open="conflict-{{ $conflict->id }}-{{ $decision }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    @foreach(['reschedule' => 'Request Reschedule', 'cancel' => 'Request Cancellation'] as $decision => $label)
                        <x-modal id="conflict-{{ $conflict->id }}-{{ $decision }}" title="{{ $label }}" size="small">
                            <form method="POST" action="{{ route('official-use-conflicts.decision', $conflict) }}" data-modal-action>
                                @csrf
                                <input type="hidden" name="decision" value="{{ $decision }}">
                                <div class="facility-modal-body"><p>{{ $label }} for {{ $reservation->facility_name }}?</p><p>The barangay administrator will process your request.</p><p data-action-error role="alert" hidden></p></div>
                                <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Confirm Request</button></div>
                            </form>
                        </x-modal>
                    @endforeach
                @endunless
            </div>
        @endif
    @endif
@endforeach
