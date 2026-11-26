# Capabilities

| Capability | State | Scope |
| --- | --- | --- |
| Application deployment | Supported | Prebuilt local files over FTP or verified explicit FTPS; repeat deploy and tracked-file cleanup |
| Profiles | Supported | Separate local/remote paths and exclusions per profile |
| Observability | Partial | Saved remote manifest status; no runtime health, metrics or logs |
| Rollback | Unsupported | Redeploy an earlier local artifact instead |
| Server lifecycle | Unsupported | Existing FTP account required |
| Domain management | Unsupported | Configure hosting and DNS separately |
| SSL | Unsupported | No website certificate provisioning; FTPS transport TLS is supported |
| Environment management | Unsupported | Prepare application configuration separately |
| Databases | Unsupported | No database provisioning or migrations |
| Background workloads | Unsupported | No workers, schedulers or process management |
| Previews | Unsupported | No automatic PR environments or orphan cleanup |

`FtpProvider::capabilities()` exposes the same states through the shared
Shipper contracts. Cleanup is limited to the matching manifest's file list.
