<!DOCTYPE html>
<html lang="en">

@include('layouts.partials.header_script')

<body>
    <!--wrapper-->
    <div class="wrapper">

        @include('layouts.partials.sidebar')

        @include('layouts.partials.header')

        @yield('content')

        @include('layouts.partials.footer')

        <div class="overlay toggle-btn-mobile"></div>

    </div>
    <!--end wrapper-->

    @include('layouts.partials.footer_script')

    @stack('scripts')
</body>

</html>
