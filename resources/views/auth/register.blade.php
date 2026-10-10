<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | Symptom Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>:root { --brand: #0f766e; } .brand { color: var(--brand); } .btn-brand { background: var(--brand); border-color: var(--brand); color: #fff; }</style>
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width: 460px;">
    <div class="text-center mb-4">
        <h1 class="h3 brand"><i class="bi bi-heart-pulse"></i> Symptom Tracker</h1>
        <p class="text-body-secondary mb-0">Create a clinician account</p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ url('/register') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label for="name" class="form-label">Full name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required autofocus>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">At least 8 characters.</div>
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirm password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-brand w-100">Create account</button>
            </form>
        </div>
    </div>

    <p class="text-center small mt-3 mb-1">Already have an account? <a href="{{ route('login') }}">Log in</a></p>
    <p class="text-center small text-body-secondary">New accounts can view the dashboard and reports. Only the lead clinician can add or change patients.</p>
    <p class="text-center small text-body-secondary">Simulated data only. Not for clinical use.</p>
</main>
</body>
</html>
