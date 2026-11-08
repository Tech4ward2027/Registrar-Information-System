#!/usr/bin/env bash
# Daily archive of application files in the ris-file-storage bucket
# (policy: DR 3.3 "Media & Document Storage"). Schedule: 02:00 PHT = 18:00 UTC.
#
# The host root volume is only 20 GB, so this script measures the bucket first
# and refuses to run if the copy would not fit. If the bucket outgrows the
# disk, switch to S3 versioning + replication for files and keep this tarball
# for a smaller subset (see README, "Media bucket size").
source "$(dirname "$(readlink -f "$0")")/lib.sh"
init media

require_cmd aws; require_cmd tar; require_cmd gzip
: "${MEDIA_BUCKET:=ris-file-storage}"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
src="$WORKDIR/media"
payload="$WORKDIR/ris-media-$stamp.tar.gz"
final="$BACKUP_ROOT/media/ris-media-$stamp.tar.gz.gpg"
mkdir -p "$src"

bucket_bytes="$(aws s3 ls "s3://$MEDIA_BUCKET" --recursive --summarize --region "$AWS_REGION" \
                | awk '/Total Size:/ {print $3}')"
bucket_bytes="${bucket_bytes:-0}"
free_bytes="$(df --output=avail -B1 "$BACKUP_ROOT" | tail -n1 | tr -d ' ')"
# Need roughly: downloaded copy + tarball + encrypted copy.
if (( bucket_bytes * 3 > free_bytes )); then
  die "bucket is $bucket_bytes bytes but only $free_bytes free; refusing to fill the disk"
fi
log INFO "syncing s3://$MEDIA_BUCKET ($bucket_bytes bytes)"

aws s3 sync "s3://$MEDIA_BUCKET" "$src" --region "$AWS_REGION" --only-show-errors
files="$(find "$src" -type f | wc -l)"
log INFO "downloaded $files file(s)"

tar -C "$WORKDIR" -cf - media | gzip -6 > "$payload"
gzip -t "$payload" || die "gzip integrity test failed"
rm -rf "$src"

payload_sha="$(sha256_of "$payload")"
encrypt_file "$payload" "$final"
enc_sha="$(sha256_of "$final")"
rm -f "$payload"

finish_backup media "$final" "$payload_sha" "$enc_sha"
