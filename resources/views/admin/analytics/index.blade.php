@extends('layouts.admin')

@section('title', 'App Analytics')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        @include('admin.analytics.partials.dashboard', ['data' => $data, 'ranges' => $ranges])
    </div>
</div>
@endsection
