{{-- Due-date badge. Needs $account, $status and $service. --}}
@if ($status === 'none')
    <span class="muted">No rotation</span>
@elseif ($status === 'unknown')
    <span class="badge unknown" title="Rotation is set but no reset date is recorded">Unknown</span>
@else
    <span class="badge {{ ['ok' => 'ok', 'due_soon' => 'warning', 'overdue' => 'critical'][$status] }}">{{ ['ok' => 'OK', 'due_soon' => 'Due soon', 'overdue' => 'Overdue'][$status] }}</span>
    <span class="muted">{{ \App\Utils\LocalTime::format($service->dueAt($account), 'Y-m-d') }}</span>
@endif
