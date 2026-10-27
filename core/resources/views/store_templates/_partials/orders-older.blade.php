{{-- P6-302: "Load more" for the cursor-paginated account order history. $nextBefore is the id of the oldest order on this page (null on the last page). --}}
@if(!empty($nextBefore))
    <div class="orders-older" style="text-align:center;margin:14px 0">
        <a class="ghostbtn" data-orders-older href="{{ request()->url() }}?before={{ (int) $nextBefore }}">{{ ___('Load more') }}</a>
    </div>
@endif
