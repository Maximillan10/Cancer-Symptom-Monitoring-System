@extends('layouts.app')

@section('title', 'Edit patient')

@section('content')
    <div class="mb-3">
        <h1 class="h3 mb-0">Edit {{ $patient->name }}</h1>
    </div>

    <form method="POST" action="{{ route('dashboard.patients.update', $patient) }}" novalidate>
        @csrf
        @method('PUT')
        <div class="card mb-3"><div class="card-body">@include('patients._form')</div></div>
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="{{ route('dashboard.patient', $patient) }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
@endsection
