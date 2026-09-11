output "service_url" {
  description = "Base URL of the Cloud Run service."
  value       = google_cloud_run_v2_service.app.uri
}

output "webhook_url" {
  description = "Endpoint both push subscriptions deliver to."
  value       = "${google_cloud_run_v2_service.app.uri}/api/stateless/webhook"
}

output "default_topic" {
  description = "Topic for jobs that declare no topic of their own."
  value       = google_pubsub_topic.default.name
}

output "custom_topic" {
  description = "Topic for jobs that declare $statelessTopic."
  value       = google_pubsub_topic.custom.name
}

output "dead_letter_topic" {
  description = "Where permanently-failing messages land after 5 attempts."
  value       = google_pubsub_topic.dead_letter.name
}

output "push_service_account" {
  description = "The only identity the webhook accepts."
  value       = google_service_account.pubsub_push.email
}

output "app_service_account" {
  description = "Cloud Run runtime identity, which publishes to the topics."
  value       = google_service_account.app.email
}

output "image_repository" {
  description = "Artifact Registry path images are pushed to."
  value       = "${var.region}-docker.pkg.dev/${var.project_id}/${google_artifact_registry_repository.images.repository_id}"
}

output "dispatch_token" {
  description = "Token for the example's /dispatch/* routes."
  value       = coalesce(var.dispatch_token, random_password.dispatch_token.result)
  sensitive   = true
}

output "caller_service_account" {
  description = "Identity verify.sh impersonates to reach the authenticated service."
  value       = google_service_account.caller.email
}
