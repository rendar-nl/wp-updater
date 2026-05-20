# wp-updater TODO

## Release Artifact Signing

- [ ] Design release artifact signing for private WordPress plugin ZIPs.
- [ ] Decide the metadata contract, for example `package_sha256`, detached signature URL, signing key id, and signature algorithm.
- [ ] Decide where trusted public keys live in consuming plugins or the updater package.
- [ ] Verify packages before WordPress installs or upgrades them.
- [ ] Define key rotation and revocation behavior.
- [ ] Document operational steps for generating, publishing, rotating, and retiring signing keys.
- [ ] Add failure behavior that is safe by default and keeps WordPress error logs clean unless intentional logging is enabled.
- [ ] Add tests for valid signatures, invalid signatures, missing signatures, key rotation, and rollback behavior.

## Compare With 043 Updater

- [ ] Compare this updater with `/Users/rob/PhpstormProjects/043core/vendor/web043/updater`.
- [ ] Review the recently updated 043 updater behavior for metadata caching, backup metadata, ETag support, update hook registration, and failure handling.
- [ ] Decide which 043 updater improvements should be ported into `rendar/wp-updater`.
- [ ] Check whether 043 updater release workflow changes should influence this repository's release workflows.
- [ ] Document intentional differences between the Rendar updater and 043 updater.
