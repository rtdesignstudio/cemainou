{{ $greeting }}

{{ __('Download cemainou :version', ['version' => $version]) }}: {{ $url }}

{{ __('Installing, in three steps') }}
1. {{ __('Upload the archive to your hosting, in a folder of its own, and extract it. Only public/ should be visible on the web.') }}
2. {{ __('Open the address in a browser: the installer checks the server and asks for the database, your company and the first admin.') }}
3. {{ __('Connect your agents from Profile → Agents. The full guide is in the archive (docs/installation.md) and on the page.') }}

{{ __('Requirements and documentation') }}: {{ $page }}

{{ __('Questions? Just reply to this email.') }}
