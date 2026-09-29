{{-- What the MariaDB monitoring user needs, and the optional extras. --}}
<p>The checks need these privileges; a check without them reports <em>Unknown</em>:</p>
<code class="pubkey">GRANT SELECT, PROCESS, SLAVE MONITOR ON *.* TO 'sys_monitor'@'this-host';</code>
<p class="hint">SELECT lets the crashed-table check run CHECK TABLE and read database sizes and performance_schema; SLAVE MONITOR (MariaDB 10.5+; REPLICATION CLIENT on older versions and MySQL) lets it read replication status.</p>
<p style="margin-top: 12px"><strong>Optional, for servers without SSH:</strong></p>
<ul class="hint">
    <li><strong>Disk space</strong> through MariaDB: install the DISKS plugin (<code>INSTALL SONAME 'disks';</code>) and grant <code>FILE</code>. FILE also lets the user read files the server can read, so grant it only if that's acceptable.</li>
    <li><strong>File I/O</strong>: turn on <code>performance_schema</code> in the server's options (it needs a restart); the SELECT above covers reading it.</li>
</ul>
<p class="hint">Without these, those checks say "not available" and stay OK.</p>
