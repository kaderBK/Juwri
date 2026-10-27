{{-- conformance:allow-raw core-sanitised owner HTML --}}
{!! $sanitised !!}
<script type="application/json" id="vm">@json($vm)</script>
<script src="/js/theme.js" defer></script>
<a href="{{ $url }}" target="_blank" rel="noopener noreferrer">ok</a>
<svg xmlns="http://www.w3.org/2000/svg"></svg>
{{-- post_options( mentioned in a comment is fine --}}
<button data-juwri-add="1">Add</button>
