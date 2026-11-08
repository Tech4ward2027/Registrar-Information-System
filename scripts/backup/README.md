# RIS backup and restore scripts

Implements the backup and restore parts of the *Backup & DR Plan* and the *BCDR Policy* for the
**current AWS production** (EC2 + RDS + S3). Everything is plain bash and has no AWS-only logic
except the S3 calls, so it moves to the Hostinger KVM VPS unchanged.

| Script | Policy item | Schedule (PHT / UTC cron) |
|---|---|---|
| `ris-backup-db.sh` | Daily full logical dump, gzip + AES-256 GPG (DR 3.3) | 01:00 PHT / `0 17 * * *` |
| `ris-backup-media.sh` | Media and documents archive (DR 3.3) | 02:00 PHT / `0 18 * * *` |
| `ris-backup-config.sh` | `.env`, nginx, TLS, SSH config (DR scope item 3) | 02:30 PHT / `30 18 * * *` |
| `ris-backup-check.sh` | Dead-man check, alerts if a backup stops running | every 6 h |
| `ris-restore-drill.sh` | Restore + checksum gates + audit:verify + break-glass:test (DR 3.4, 3.5; EV-BCDR-03) | run by hand on the **drill machine** |

The host clock is UTC, the policy is written in PHT. The cron file already has the conversion.

## What these scripts do not cover

* **The 15-minute RPO.** On RDS this is met by point-in-time restore, not by binlog streaming. RDS
  ships transaction logs to S3 about every 5 minutes; the console shows *Latest restorable time*.
  Keep automated backup retention at 7 days or more (the evidence screenshot says 3 days, the
  Deployment Documentation says 1: confirm in the console). Binlog streaming becomes the mechanism
  after migration to self-managed MySQL on Hostinger.
* **Weekly server image (Sunday 03:00 PHT = Saturday 19:00 UTC).** Use AWS Data Lifecycle Manager
  (EBS-backed AMI policy for `ris-backend-server`). Not a script.
* **Air-gapped NAS sync.** A manual weekly procedure with a signed checklist and a named owner.
* **Media bucket size.** The script refuses to run if the bucket would not fit on the 20 GB disk.
  If it ever does, enable S3 versioning and replication on `ris-file-storage` and treat the tarball as a
  secondary copy.

## Setup order

1. **Offsite vault bucket (separate from `ris-file-storage`).** Create it with Object Lock enabled at
   creation time (cannot be added later), versioning on, region ap-southeast-1:
   ```
   aws s3api create-bucket --bucket <vault> --region ap-southeast-1 \
     --create-bucket-configuration LocationConstraint=ap-southeast-1 --object-lock-enabled-for-bucket
   aws s3api put-object-lock-configuration --bucket <vault> --object-lock-configuration \
     '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":30}}}'
   ```
   COMPLIANCE mode means nobody, including root, can delete objects for 30 days. That is the
   ransomware protection the policy asks for, and it also means mistakes cost money. Use `GOVERNANCE`
   while testing. Add a lifecycle rule to expire versions after retention plus a margin.
2. **Backup IAM user** (new; not `ris-s3-user`, not `ris-ci-deploy`), keys in
   `/etc/ris-backup/aws-credentials` under profile `ris-backup`, plus MFA on the console user that
   owns it. Policy:
   ```json
   {"Version":"2012-10-17","Statement":[
     {"Effect":"Allow","Action":["s3:PutObject","s3:GetObject"],"Resource":"arn:aws:s3:::<vault>/ris/*"},
     {"Effect":"Allow","Action":["s3:ListBucket"],"Resource":["arn:aws:s3:::<vault>","arn:aws:s3:::ris-file-storage"]},
     {"Effect":"Allow","Action":["s3:GetObject"],"Resource":"arn:aws:s3:::ris-file-storage/*"}
   ]}
   ```
   No `DeleteObject`, no `PutBucketObjectLockConfiguration`. Restore from the vault uses separate
   read-only credentials on the drill machine.
3. **Database user** (run once as the RDS admin):
   ```sql
   CREATE USER 'ris_backup'@'%' IDENTIFIED BY '<strong password>' REQUIRE SSL;
   GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON registrar_information_system.* TO 'ris_backup'@'%';
   ```
   `ris-backup-db.sh` falls back to the application's `DB_*` values if this is skipped, but that
   gives the backup job write access to production. Prefer the dedicated user. The RDS security
   group must allow the EC2 host (already true, since the app connects).
4. **Encryption key pair.** Generate it on a **trusted laptop, not the server**:
   `gpg --quick-gen-key "RIS Backup <address>" ed25519 cert never` then
   `gpg --quick-add-key <FPR> cv25519 encr 2y`. Export the public key
   (`gpg --export -a <FPR> > ris-backup-public.asc`) and import only that on the server into
   `/etc/ris-backup/gnupg`. Keep the **private key and its passphrase** in at least two places held by
   different people (for example the Campus Registrar and the project lead), written into the policy's
   key-custody section. **If the private key is lost, every backup is unreadable.** Test recovery
   with the drill before relying on it.
5. **Install.** `sudo ./install.sh`, then edit `/etc/ris-backup/backup.env`.
6. **Run each script once by hand** as the backup user (`config` as root) and read the log:
   ```
   /opt/ris-backup/ris-backup-db.sh && tail /var/log/ris/backup.log
   ```
7. **Enable cron** only after step 6 passes: `sudo cp /etc/ris-backup/ris-backup.cron.disabled /etc/cron.d/ris-backup`.
8. **Install the MySQL client on the host.** `install.sh` does this (`mysql-client-core-8.0`). Do not
   rely on a `mysql` Docker image on the host: the deploy workflow runs `docker image prune -a -f`.

## Evidence

* **EV-BCDR-02:** `cat /etc/cron.d/ris-backup`, `tail -50 /var/log/ris/backup.log`, `./ris-backup-check.sh`.
  State the environment (production, AWS EC2) and capture the date and time zone in the screenshot.
* **EV-BCDR-03:** the report written by `ris-restore-drill.sh`, plus a terminal screenshot. The current
  evidence is a list of RDS automated backups, which is not a restoration test.

## Restore drill

On the drill machine (a locked-down Hostinger box or scratch server; it will hold real student data):

```
export GNUPGHOME=/path/to/keyring-with-private-key
export DRILL_ENV_LABEL="Hostinger staging, <RAM>/<vCPU>, <date>"
export RESTORE_APP_IMAGE=<ecr-repo>:<tag>      # so audit:verify and break-glass:test run
export RESTORE_APP_KEY=<APP_KEY of the dump's application>
./ris-restore-drill.sh --ledger ledger.log --from-s3 --i-understand-this-loads-real-student-data
```

It stops at the first failed gate: ledger checksum, GPG decryption, payload checksum, gzip test,
SQL errors, empty tables, `audit:verify`, `break-glass:test`. Wipe the machine afterwards and note
that in the drill record.

## Known limits

* The vault upload verifies object size, not a server-side checksum. Restore-time SHA-256 is the
  real gate.
* The ledger is tamper-evident (each line hashes the previous one) but lives on the host. Each
  backup also ships a copy to the vault; compare them during drills.
* Steps 4 to 7 of the drill (MySQL container, load, artisan checks) have not been run end-to-end
  yet; steps 1 to 3 and the whole backup path were tested with stubbed `mysqldump` and S3.
