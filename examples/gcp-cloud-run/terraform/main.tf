locals {
  # One constant shared by three places that must agree: the audience Pub/Sub
  # mints into the token, the audience Cloud Run will accept, and the audience
  # the package requires. If they drift, the webhook returns 403.
  audience = var.webhook_audience

  webhook_path = "/api/stateless/webhook"
}

data "google_project" "this" {
  project_id = var.project_id
}

resource "google_project_service" "required" {
  for_each = toset([
    "run.googleapis.com",
    "pubsub.googleapis.com",
    "artifactregistry.googleapis.com",
    "iam.googleapis.com",
    "logging.googleapis.com",
  ])

  project = var.project_id
  service = each.value

  # Leave the APIs enabled on destroy; disabling them can break unrelated
  # resources that happen to share the project.
  disable_on_destroy = false
}

resource "random_password" "dispatch_token" {
  length  = 40
  special = false
}

# ─────────────────────────────────────────────────────────────────────────────
# Identities
#
# Two service accounts, because they are two different trust decisions:
#   - the app's own identity, which publishes to the topics
#   - the identity Pub/Sub presents when it calls the webhook
#
# Keeping them apart is what makes `allowed_service_accounts` meaningful: the
# webhook accepts exactly one caller, and it is not the app itself.
# ─────────────────────────────────────────────────────────────────────────────

resource "google_service_account" "app" {
  project      = var.project_id
  account_id   = "${var.service_name}-app"
  display_name = "Cloud Run runtime identity for ${var.service_name}"
  depends_on   = [google_project_service.required]
}

resource "google_service_account" "pubsub_push" {
  project      = var.project_id
  account_id   = "${var.service_name}-push"
  display_name = "Identity Pub/Sub uses to invoke ${var.service_name}"
  depends_on   = [google_project_service.required]
}

# Pub/Sub's own service agent needs permission to mint OIDC tokens as the push
# identity above. Without this the subscriptions are created but every delivery
# fails before it leaves Google.
resource "google_service_account_iam_member" "pubsub_mints_tokens" {
  service_account_id = google_service_account.pubsub_push.name
  role               = "roles/iam.serviceAccountTokenCreator"
  member             = "serviceAccount:service-${data.google_project.this.number}@gcp-sa-pubsub.iam.gserviceaccount.com"
}

# A third identity, for the test harness only. Deliberately not the app SA:
# that one can publish to the topics, and a principal that merely needs to call
# an HTTP route should not inherit publish rights. This one holds run.invoker
# and nothing else.
resource "google_service_account" "caller" {
  project      = var.project_id
  account_id   = "${var.service_name}-caller"
  display_name = "Test-harness identity for invoking ${var.service_name}"
  depends_on   = [google_project_service.required]
}

resource "google_service_account_iam_member" "caller_impersonation" {
  for_each = toset(var.caller_members)

  service_account_id = google_service_account.caller.name
  role               = "roles/iam.serviceAccountTokenCreator"
  member             = each.value
}

# ─────────────────────────────────────────────────────────────────────────────
# Topics
# ─────────────────────────────────────────────────────────────────────────────

resource "google_pubsub_topic" "default" {
  project    = var.project_id
  name       = var.default_topic_name
  depends_on = [google_project_service.required]
}

resource "google_pubsub_topic" "custom" {
  project    = var.project_id
  name       = var.custom_topic_name
  depends_on = [google_project_service.required]
}

# A message the webhook rejects — a disallowed class, a malformed payload — gets
# a 4xx, which is a permanent failure. Without somewhere to put those, Pub/Sub
# retries to its limit and then drops them silently.
resource "google_pubsub_topic" "dead_letter" {
  project    = var.project_id
  name       = "${var.service_name}-dead-letter"
  depends_on = [google_project_service.required]
}

resource "google_pubsub_topic_iam_member" "app_publishes_default" {
  project = var.project_id
  topic   = google_pubsub_topic.default.name
  role    = "roles/pubsub.publisher"
  member  = "serviceAccount:${google_service_account.app.email}"
}

resource "google_pubsub_topic_iam_member" "app_publishes_custom" {
  project = var.project_id
  topic   = google_pubsub_topic.custom.name
  role    = "roles/pubsub.publisher"
  member  = "serviceAccount:${google_service_account.app.email}"
}

# ─────────────────────────────────────────────────────────────────────────────
# Container registry
# ─────────────────────────────────────────────────────────────────────────────

resource "google_artifact_registry_repository" "images" {
  project       = var.project_id
  location      = var.region
  repository_id = var.service_name
  format        = "DOCKER"
  description   = "Images for the stateless-queue Cloud Run example"
  depends_on    = [google_project_service.required]
}

# ─────────────────────────────────────────────────────────────────────────────
# Cloud Run
# ─────────────────────────────────────────────────────────────────────────────

resource "google_cloud_run_v2_service" "app" {
  project  = var.project_id
  name     = var.service_name
  location = var.region

  # Requires authentication. Pub/Sub reaches it because of the invoker binding
  # below, and nothing else can call it at all.
  ingress = "INGRESS_TRAFFIC_ALL"

  # Lets Cloud Run accept a token whose `aud` is this constant instead of the
  # service URL. Without it the audience would have to be the URL, which is not
  # known until the service exists — a cycle, since the value has to be in the
  # service's own environment.
  custom_audiences = [local.audience]

  deletion_protection = false

  template {
    service_account = google_service_account.app.email

    scaling {
      min_instance_count = var.min_instances
      max_instance_count = var.max_instances
    }

    containers {
      image = var.image

      ports {
        container_port = 8080
      }

      resources {
        limits = {
          cpu    = "1"
          memory = "512Mi"
        }
        # CPU only while a request is in flight. This is the scale-to-zero
        # posture the package is built for; a polling worker could not survive
        # it, which is the whole point of the example.
        cpu_idle = true
      }

      env {
        name  = "APP_ENV"
        value = "production"
      }

      env {
        name  = "APP_DEBUG"
        value = "false"
      }

      env {
        name  = "APP_KEY"
        value = "base64:${base64encode(substr(sha256(var.service_name), 0, 32))}"
      }

      env {
        name  = "LOG_CHANNEL"
        value = "stderr"
      }

      # No database, cache server, or queue backend in this example. Laravel's
      # defaults reach for one; these keep every subsystem in memory.
      env {
        name  = "SESSION_DRIVER"
        value = "array"
      }

      env {
        name  = "CACHE_STORE"
        value = "array"
      }

      env {
        name  = "QUEUE_CONNECTION"
        value = "sync"
      }

      env {
        name  = "GOOGLE_CLOUD_PROJECT"
        value = var.project_id
      }

      env {
        name  = "STATELESS_QUEUE_ADAPTER"
        value = "google"
      }

      env {
        name  = "STATELESS_QUEUE_TOPIC"
        value = google_pubsub_topic.default.name
      }

      # The three checks that make the webhook safe to expose. The audience pins
      # which service the token was minted for; the service account pins who
      # minted it.
      env {
        name  = "STATELESS_QUEUE_GOOGLE_EXPECTED_AUDIENCE"
        value = local.audience
      }

      env {
        name  = "STATELESS_QUEUE_GOOGLE_ALLOWED_SERVICE_ACCOUNTS"
        value = google_service_account.pubsub_push.email
      }

      env {
        name  = "STATELESS_QUEUE_GOOGLE_ALLOWED_ISSUERS"
        value = "https://accounts.google.com,accounts.google.com"
      }

      env {
        name  = "DISPATCH_TOKEN"
        value = coalesce(var.dispatch_token, random_password.dispatch_token.result)
      }

      startup_probe {
        http_get {
          path = "/healthz"
          port = 8080
        }
        initial_delay_seconds = 3
        timeout_seconds       = 3
        period_seconds        = 5
        failure_threshold     = 6
      }
    }
  }

  depends_on = [google_project_service.required]
}

# The caller identity may invoke the service too, so the dispatch harness can be
# driven from a laptop. It cannot publish, so it cannot bypass the webhook.
resource "google_cloud_run_v2_service_iam_member" "caller_invokes" {
  count = length(var.caller_members) > 0 ? 1 : 0

  project  = var.project_id
  location = google_cloud_run_v2_service.app.location
  name     = google_cloud_run_v2_service.app.name
  role     = "roles/run.invoker"
  member   = "serviceAccount:${google_service_account.caller.email}"
}

# Pub/Sub's own invoker binding — the one that matters in production.
resource "google_cloud_run_v2_service_iam_member" "pubsub_invokes" {
  project  = var.project_id
  location = google_cloud_run_v2_service.app.location
  name     = google_cloud_run_v2_service.app.name
  role     = "roles/run.invoker"
  member   = "serviceAccount:${google_service_account.pubsub_push.email}"
}

# ─────────────────────────────────────────────────────────────────────────────
# Push subscriptions
#
# One per topic, both pointing at the same webhook. Routing is a property of the
# topic, not the endpoint — which is what the custom-topic job demonstrates.
# ─────────────────────────────────────────────────────────────────────────────

resource "google_pubsub_subscription" "default" {
  project = var.project_id
  name    = "${var.default_topic_name}-push"
  topic   = google_pubsub_topic.default.id

  push_config {
    push_endpoint = "${google_cloud_run_v2_service.app.uri}${local.webhook_path}"

    oidc_token {
      service_account_email = google_service_account.pubsub_push.email
      audience              = local.audience
    }
  }

  # Generous enough to cover a cold start on a scale-to-zero service.
  ack_deadline_seconds = 60

  retry_policy {
    minimum_backoff = "10s"
    maximum_backoff = "600s"
  }

  dead_letter_policy {
    dead_letter_topic     = google_pubsub_topic.dead_letter.id
    max_delivery_attempts = 5
  }

  depends_on = [google_cloud_run_v2_service_iam_member.pubsub_invokes]
}

resource "google_pubsub_subscription" "custom" {
  project = var.project_id
  name    = "${var.custom_topic_name}-push"
  topic   = google_pubsub_topic.custom.id

  push_config {
    push_endpoint = "${google_cloud_run_v2_service.app.uri}${local.webhook_path}"

    oidc_token {
      service_account_email = google_service_account.pubsub_push.email
      audience              = local.audience
    }
  }

  ack_deadline_seconds = 60

  retry_policy {
    minimum_backoff = "10s"
    maximum_backoff = "600s"
  }

  dead_letter_policy {
    dead_letter_topic     = google_pubsub_topic.dead_letter.id
    max_delivery_attempts = 5
  }

  depends_on = [google_cloud_run_v2_service_iam_member.pubsub_invokes]
}

# Dead-lettering is performed by the Pub/Sub service agent, which needs to
# publish to the dead-letter topic and acknowledge on the source subscriptions.
resource "google_pubsub_topic_iam_member" "dead_letter_publisher" {
  project = var.project_id
  topic   = google_pubsub_topic.dead_letter.name
  role    = "roles/pubsub.publisher"
  member  = "serviceAccount:service-${data.google_project.this.number}@gcp-sa-pubsub.iam.gserviceaccount.com"
}

resource "google_pubsub_subscription_iam_member" "dead_letter_subscriber_default" {
  project      = var.project_id
  subscription = google_pubsub_subscription.default.name
  role         = "roles/pubsub.subscriber"
  member       = "serviceAccount:service-${data.google_project.this.number}@gcp-sa-pubsub.iam.gserviceaccount.com"
}

resource "google_pubsub_subscription_iam_member" "dead_letter_subscriber_custom" {
  project      = var.project_id
  subscription = google_pubsub_subscription.custom.name
  role         = "roles/pubsub.subscriber"
  member       = "serviceAccount:service-${data.google_project.this.number}@gcp-sa-pubsub.iam.gserviceaccount.com"
}
