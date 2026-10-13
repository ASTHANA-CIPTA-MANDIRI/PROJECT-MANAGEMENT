@php($socials = $getState())
<div class="rk-login-via">
    @forelse($socials as $social)
        @switch($social->provider)
            @case('google')
                <span class="rk-login-via__chip"><x-icon name="fab-google" class="social-icon google"/>Google</span>
                @break
            @case('github')
                <span class="rk-login-via__chip"><x-icon name="fab-github" class="social-icon github"/>GitHub</span>
                @break
        @endswitch
    @empty
        <span class="rk-login-via__chip rk-login-via__chip--mail"><x-heroicon-o-mail class="h-4 w-4"/>{{ __('Email') }}</span>
    @endforelse
</div>
