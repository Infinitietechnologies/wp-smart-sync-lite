# Google service-account P12 support

Status: implemented. Generated certificate/key fixtures and browser behavior pass; a real Google-issued P12 account has not been tested.

The uploader previously accepted only JSON, so binary PKCS12 files failed extension/JSON validation. It now accepts one `.json` or `.p12` file up to 1 MiB. Existing JSON preview and saved-credential reuse remain available.

P12 selection displays required service-account email and storage project ID fields, plus an optional password. Blank uses Google's standard `notasecret` password. The browser shows file metadata and account inputs; it neither parses nor displays the binary private key. Changing any account field invalidates prior discovery. New P12 credentials are submitted only with discovery and use its existing user-bound selection token for saving.

PHP validates the actual file size, account inputs, PKCS12 password, RSA key and matching certificate. OpenSSL's decoded PKCS8 key and supplied account/project fields are normalized into the existing service-account representation and encrypted through the existing site-bound AES-GCM store. The P12 password and original binary are not copied into persistent plugin storage. Key signing is tested against the generated certificate's public key. Reuse requires no repeat P12/password upload. Successful configuration or removing/replacing the file clears the local P12 account/password fields.

Older P12 files can use legacy encryption unavailable in OpenSSL 3. Unsupported encryption produces an actionable JSON/re-export message; no legacy provider, global OpenSSL settings or verification bypass is enabled. Incorrect passwords, corrupt files, missing metadata, non-RSA keys and oversize uploads fail without storing credentials or exposing passwords, raw keys, provider responses or private paths.

Validation covers PHP crypto/storage/token binding, admin field validation and request payloads, operation locks, stale discovery invalidation, JSON replacement and safe clearing. The actual browser uploader was checked at 320, 390, 768, 1440 and 1920 px, with screenshots in the system temp `smartsync-json-upload-preview` directory. Cloud requests in these checks are intercepted.

References: [Google service-account key formats](https://docs.cloud.google.com/iam/docs/keys-create-delete), [Google's P12 password documentation](https://support.google.com/googleapi/answer/6158849?hl=en).
