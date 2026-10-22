<p>Care Core is plain PHP and MySQL with no framework and no build step. It runs on ordinary shared hosting.</p>

<h2>Requirements</h2>
<ul>
<li><strong>PHP 8.1 or newer</strong> with the extensions <code>pdo_mysql</code>, <code>openssl</code>, <code>curl</code>, <code>mbstring</code>, <code>fileinfo</code> and <code>json</code> (all normally enabled).</li>
<li><strong>MySQL 8</strong> or <strong>MariaDB 10.5</strong> and later, with <code>utf8mb4</code>.</li>
<li><strong>Apache</strong> with <code>mod_rewrite</code> (an <code>.htaccess</code> is provided), or any server that sends every request to <code>public/index.php</code>.</li>
<li><strong>HTTPS</strong> for any real use.</li>
<li>Cron (or a scheduler) for two small jobs.</li>
<li>PHP’s <code>upload_max_filesize</code> and <code>post_max_size</code> of at least <strong>4 MB</strong>, so 3 MB photos can be uploaded.</li>
</ul>
<div class="note"><strong>Keep PHP patched.</strong> Check your host runs a currently supported, patched PHP release.</div>

<h2>Install</h2>
<ol>
<li><strong>Get the code</strong> onto the server (clone the repository or upload it). If the web root is the repository root, the included top-level <code>.htaccess</code> serves only <code>public/</code> and blocks <code>app/</code>, <code>database/</code>, <code>scripts/</code>, <code>tests/</code>, <code>docs/</code>, <code>storage/</code> and dotfiles. If you can set the web root, point it at <code>public/</code>.</li>
<li><strong>Create a database</strong> and a database user with rights to it.</li>
<li><strong>Create <code>.env</code></strong> from <code>.env.example</code> and fill it in (see below).</li>
<li><strong>Add the encryption key:</strong> <code>php scripts/make-key.php</code> adds <code>APP_KEY</code> to <code>.env</code> once and never overwrites an existing key.</li>
<li><strong>Create the tables:</strong> <code>php scripts/migrate.php</code>.</li>
<li><strong>Create your organisation and its first administrator:</strong>
<pre><code>php scripts/create-organisation.php "Your Organisation" you@example.org</code></pre>
It prints a one-time invitation link: open it to choose your own password, then sign in. (You can set up Microsoft sign-in after that.) (For a quick look at Care Core with sample data instead, load the demo: see <a href="/docs/testing">Tests and demo data</a>.)</li>
<li><strong>Schedule the jobs</strong> (see <a href="/docs/operations">operations</a>).</li>
</ol>

<h2>Settings (<code>.env</code>)</h2>
<p>A real environment variable of the same name overrides the file.</p>
<div class="tablewrap"><table>
<thead><tr><th>Setting</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><code>APP_ENV</code></td><td><code>production</code> normally.</td></tr>
<tr><td><code>APP_DEBUG</code></td><td><code>false</code> in production. When true, errors are shown on screen: never on a live site.</td></tr>
<tr><td><code>APP_URL</code></td><td>The public address, for example <code>https://core.example.org</code>. Used for invitation links, the SCIM address and the Microsoft redirect address. (<code>SITE_URL</code> is accepted as an alias.)</td></tr>
<tr><td><code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code></td><td>Database connection. On shared hosting use <code>127.0.0.1</code> and port <code>3306</code>.</td></tr>
<tr><td><code>APP_KEY</code></td><td>32 random bytes, base64. Encrypts National Insurance and bank details. <strong>Back it up separately and never change it.</strong></td></tr>
<tr><td><code>DEMO_MODE</code></td><td><code>true</code> shows a demo banner, enables the demo login help and allows the demo reset script. Never on a live installation.</td></tr>
<tr><td><code>OWNER_EMAIL</code>, <code>OWNER_PASSWORD_HASH</code></td><td>Demo installations only: an administrator login of your own that is put back after every nightly reset. The hash is made on your own computer (see <code>.env.example</code>); the password itself is never stored in a file.</td></tr>
<tr><td><code>ENTRA_TENANT_ID</code>, <code>ENTRA_CLIENT_ID</code>, <code>ENTRA_CLIENT_SECRET</code></td><td>Microsoft sign-in. See <a href="/docs/entra-signin">Sign in with Microsoft</a>.</td></tr>
<tr><td><code>ENTRA_REDIRECT_URI</code></td><td>Optional. Defaults to <code>APP_URL/login/entra/callback</code>.</td></tr>
<tr><td><code>PASSWORD_LOGIN</code></td><td><code>false</code> turns passwords off for everyone except administrators once Microsoft sign-in is configured. Default <code>true</code>.</td></tr>
</tbody></table></div>

<h2>Where files go</h2>
<p>Photos and signatures are saved under <code>storage/uploads/</code>, and a cache file for Microsoft’s signing keys under <code>storage/cache/</code>. Both directories are created as needed, must be writable by the web server’s user, must <strong>not</strong> be served by the web server, and must be <strong>backed up with the database</strong>.</p>

<h2>Running locally</h2>
<pre><code>cp .env.example .env            # set DB_*, APP_KEY (php scripts/make-key.php), DEMO_MODE=true
php scripts/migrate.php
php scripts/demo-reset.php      # optional demo data (database name must contain "demo")
php -S localhost:8990 -t public public/index.php</code></pre>
