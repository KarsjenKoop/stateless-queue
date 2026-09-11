# No default. The gcloud CLI carries an ambient default project, and a variable
# with a default here would let it be applied to whatever project happens to be
# configured. Every apply must name its target explicitly.
variable "project_id" {
  type        = string
  description = "GCP project to deploy into. Must be given explicitly."

  validation {
    condition     = length(trimspace(var.project_id)) > 0
    error_message = "project_id must be set explicitly."
  }
}

variable "region" {
  type        = string
  description = "Region for Cloud Run and Artifact Registry."
  default     = "europe-west1"
}

variable "service_name" {
  type        = string
  description = "Cloud Run service name."
  default     = "stateless-queue-example"
}

variable "image" {
  type        = string
  description = "Fully qualified container image, including tag or digest."
}

variable "default_topic_name" {
  type        = string
  description = "Topic used by jobs that declare no topic of their own."
  default     = "stateless-default"
}

variable "custom_topic_name" {
  type        = string
  description = <<-EOT
    Topic used by jobs that declare $statelessTopic. Must match the value in
    App\Jobs\NotifySlackJob, which is what makes the routing observable.
  EOT
  default     = "stateless-notifications"
}

variable "webhook_audience" {
  type        = string
  description = <<-EOT
    The `aud` claim minted on the OIDC token, registered as a custom audience on
    the Cloud Run service, and required by the package's expected_audience.

    A constant rather than the service URL, because the URL is only known after
    the service exists and putting it in the service's own environment would be
    circular. Any stable opaque string works; it is an audience identifier, not
    an address, and is never resolved.
  EOT
  default     = "stateless-queue-example"
}

variable "dispatch_token" {
  type        = string
  description = <<-EOT
    Shared token guarding the example's /dispatch/* routes, which publish to real
    topics. Leave null to have one generated and exposed as a Terraform output.
  EOT
  default     = null
  sensitive   = true
}

variable "min_instances" {
  type        = number
  description = <<-EOT
    Scale-to-zero by default. That is the situation the package exists for: with
    no instance running there is no worker to poll a queue, and jobs arrive as
    inbound requests instead.
  EOT
  default     = 0
}

variable "max_instances" {
  type        = number
  description = "Upper bound on instances, to cap spend on an example."
  default     = 3
}

variable "caller_members" {
  type        = list(string)
  default     = []
  description = <<-EOT
    Principals allowed to drive the example's /dispatch routes, e.g.
    ["user:you@example.com"].

    The service requires authentication, so a plain curl gets a 403 from Cloud
    Run before it reaches the app. A user account cannot mint an audience-scoped
    identity token for itself either — gcloud refuses, since that requires a
    service account. So these principals are granted Token Creator on a
    dedicated caller service account which holds run.invoker, and verify.sh
    impersonates it.

    Leave empty for a deployment nobody needs to poke by hand; the webhook still
    works, because Pub/Sub has its own invoker binding.
  EOT
}
