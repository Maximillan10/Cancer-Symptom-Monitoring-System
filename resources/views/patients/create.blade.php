@extends('layouts.app')

@section('title', 'Add patient')

@section('content')
    <div class="mb-3">
        <h1 class="h3 mb-0">Add patient</h1>
        <p class="text-body-secondary mb-0 small">The patient is saved to the database and appears on your dashboard straight away.</p>
    </div>

    <form method="POST" action="{{ route('dashboard.patients.store') }}" novalidate>
        @csrf
        <div class="card mb-3"><div class="card-body">@include('patients._form')</div></div>
        <button type="submit" class="btn btn-primary">Save patient</button>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
@endsection
