<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $envelope_id
 * @property int $verb_event_id
 * @property int $sequence
 * @property AuditEventType $type
 * @property CarbonImmutable $occurred_at
 * @property AuditActorType $actor_type
 * @property string|null $actor_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed> $payload
 * @property string $canonical_event
 * @property string $prev_hash
 * @property string $hash
 * @property CarbonImmutable $created_at
 * @property-read \App\Models\Envelope $envelope
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent newModelQuery()
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent newQuery()
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent query()
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereActorId($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereActorType($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereCanonicalEvent($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereCreatedAt($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereEnvelopeId($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereHash($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereId($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereIpAddress($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereOccurredAt($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent wherePayload($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent wherePrevHash($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereSequence($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereType($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereUserAgent($value)
 * @method static \App\Database\AppendOnlyBuilder<static>|AuditEvent whereVerbEventId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAuditEvent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $envelope_id
 * @property string $original_name
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $page_count
 * @property string $original_sha256
 * @property string|null $final_storage_path
 * @property string|null $final_sha256
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\Envelope $envelope
 * @method static \Database\Factories\DocumentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereEnvelopeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereFinalSha256($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereFinalStoragePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereOriginalSha256($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document wherePageCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereStoragePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperDocument {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $team_id
 * @property int $sender_id
 * @property string $title
 * @property string|null $message
 * @property \App\Enums\EnvelopeStatus $status
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property int $audit_event_count
 * @property string|null $audit_chain_head
 * @property string|null $created_ip_address
 * @property string|null $created_user_agent
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditEvent> $auditEvents
 * @property-read int|null $audit_events_count
 * @property-read \App\Models\Document|null $document
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Field> $fields
 * @property-read int|null $fields_count
 * @property-read \ArtisanBuild\BuiltForCloud\User $sender
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Signer> $signers
 * @property-read int|null $signers_count
 * @property-read \App\Models\Team $team
 * @method static \Database\Factories\EnvelopeFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope forTeam(\App\Models\Team|int $team)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereAuditChainHead($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereAuditEventCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereCreatedIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereCreatedUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereSenderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperEnvelope {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $envelope_id
 * @property int $signer_id
 * @property int $page
 * @property \App\Enums\FieldType $type
 * @property bool $required
 * @property numeric $position_x
 * @property numeric $position_y
 * @property numeric $width
 * @property numeric $height
 * @property array<array-key, mixed>|null $value
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\Envelope $envelope
 * @property-read \App\Models\Signer $signer
 * @method static \Database\Factories\FieldFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereEnvelopeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereHeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field wherePage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field wherePositionX($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field wherePositionY($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereSignerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Field whereWidth($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperField {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $envelope_id
 * @property string $name
 * @property string $email
 * @property string|null $role_label
 * @property \App\Enums\SignerStatus $status
 * @property string|null $link_token_hash
 * @property string|null $otp_hash
 * @property \Carbon\CarbonImmutable|null $otp_expires_at
 * @property int $otp_attempts
 * @property \Carbon\CarbonImmutable|null $otp_locked_at
 * @property \Carbon\CarbonImmutable|null $viewed_at
 * @property \Carbon\CarbonImmutable|null $signed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property int $position
 * @property-read \App\Models\Envelope $envelope
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Field> $fields
 * @property-read int|null $fields_count
 * @method static \Database\Factories\SignerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereEnvelopeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereLinkTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereOtpAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereOtpExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereOtpHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereOtpLockedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereRoleLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereSignedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Signer whereViewedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSigner {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Envelope> $envelopes
 * @property-read int|null $envelopes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Template> $templates
 * @property-read int|null $templates_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \ArtisanBuild\BuiltForCloud\User> $users
 * @property-read int|null $users_count
 * @method static \Database\Factories\TeamFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeam {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $team_id
 * @property int $sender_id
 * @property string $name
 * @property string $title
 * @property string|null $message
 * @property string $original_name
 * @property string $storage_path
 * @property int $size_bytes
 * @property int $page_count
 * @property string $original_sha256
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TemplateField> $fields
 * @property-read int|null $fields_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TemplateRole> $roles
 * @property-read int|null $roles_count
 * @property-read \ArtisanBuild\BuiltForCloud\User $sender
 * @property-read \App\Models\Team $team
 * @method static \Database\Factories\TemplateFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template forTeam(\App\Models\Team|int $team)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereOriginalSha256($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template wherePageCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereSenderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereStoragePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTemplate {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $template_id
 * @property int $template_role_id
 * @property int $page
 * @property \App\Enums\FieldType $type
 * @property bool $required
 * @property numeric $position_x
 * @property numeric $position_y
 * @property numeric $width
 * @property numeric $height
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\TemplateRole $role
 * @property-read \App\Models\Template $template
 * @method static \Database\Factories\TemplateFieldFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereHeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField wherePage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField wherePositionX($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField wherePositionY($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereTemplateId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereTemplateRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateField whereWidth($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTemplateField {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $template_id
 * @property string $label
 * @property int $position
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TemplateField> $fields
 * @property-read int|null $fields_count
 * @property-read \App\Models\Template $template
 * @method static \Database\Factories\TemplateRoleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole whereTemplateId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemplateRole whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTemplateRole {}
}
