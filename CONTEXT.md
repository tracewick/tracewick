# Domain terms

- **Sample observation**: a stack observed at one instant, with a weight representing one or
  more sampling periods. It does not describe every instant covered by that weight.
- **Weighted stack**: observations grouped by the same stack identity. When correlation is
  enabled, observations with different correlation identities belong to different groups.
- **Correlation identity**: the pair of trace ID and span ID associated with an observation.
  An observation may have no correlation identity.
- **Active span**: the span active in the execution context at the observation. It may differ
  from the local root span of the request.
- **Local root span**: the first local span whose parent is remote or absent. It identifies a
  different scope from the active span when local spans are nested.
- **Series labels**: attributes identifying a profiling time series, such as service and route.
  Trace and span IDs are correlation identities, not series labels.
- **Span profile**: the sampled work attributed to a selected span. It can be empty and does not
  automatically include samples attributed to that span's children.
