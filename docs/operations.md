# Operations and recovery

## Ownership and cleanup

The remote `.shipper-ftp.json` manifest identifies the project name, profile
name and deployment path using a SHA-256 identity. It contains a relative file
list, deployment state and timestamp; it contains no passwords or local
source paths. The manifest is deployment state, not cryptographic proof
against someone who already has write access to your FTP account.

Reserve all `.shipper-ftp*` names for the provider. Restrict HTTP access to
these files in your web server configuration when deploying inside a document
root. They reveal filenames even though they contain no credentials.

An existing manifest must match the project, profile and path. Invalid JSON,
unsafe paths, symlinks reported by the server, conflicting directories and
untracked destination files stop deployment. Authentication errors and denied
directory listings are failures, never treated as empty directories.

After the first deployment, unrelated files can coexist in the root. Apply
will not overwrite those files and destroy will preserve them. Empty parent
directories are retained. A file-to-directory or directory-to-file layout
change can require manual removal of empty directories or a new deployment
path. Deployments do not recursively delete directories.

## Interrupted deployment

Before uploading application files, the journal tracks the union of previous
and desired files with state `deploying`. A failed upload returns failure and
preserves the journal. Retry apply with the corrected artifact or credentials,
or run destroy to remove tracked partial files. Uploads use deterministic
temporary names under the deployment root, which destroy also removes.

If the first journal write completed but its rename was interrupted, a valid
matching journal in `.shipper-ftp-state.tmp` is recognized when it is the only
entry in the deployment directory. A truncated or invalid initial journal
requires inspection and manual cleanup before retrying. Do not remove a
manifest from an existing deployment to bypass ownership checks.

FTP does not provide an atomic multi-file transaction or a portable exclusive
lock. Run only one deployment/destroy operation at a time per directory,
including operations from other projects. Individual file renames require
server support for replacing an existing destination. An interrupted apply
can leave a mixture of old and new application files. Use a maintenance page
or a hosting platform with atomic releases if that is unacceptable.

## Protocol and filesystem assumptions

The server must implement MLSD with `file` and `dir` types, absolute paths,
MKD, STOR, RETR, RNFR/RNTO and DELE. Passive transfers use the control
connection's peer address rather than trusting the server's PASV address.
Each operation has its own timeout; this is not a total deployment deadline.
Files are buffered one at a time in PHP memory, so size the PHP memory limit
for your largest artifact file. Executable bits, ownership and timestamps are
not preserved. Server-side filesystem aliases that are hidden by the FTP
server cannot be detected by this provider; use a dedicated restricted account.

No remote shell, build hooks, database migrations, `.env` generation, DNS,
domain, certificate provisioning, process restart or runtime logs are
available. Build and prepare configuration outside this provider. Unsupported
declared project resource sections and profile runtime settings are rejected.

Transport errors contain only cURL and FTP numeric codes. They deliberately
omit raw server replies, connection URLs and credentials.
