{{- define "sample-app.name" -}}
{{- .Chart.Name }}
{{- end }}

{{- define "sample-app.fullname" -}}
{{- printf "%s-%s" .Release.Name .Chart.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "sample-app.labels" -}}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version }}
app.kubernetes.io/name: {{ include "sample-app.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{- define "sample-app.selectorLabels" -}}
app.kubernetes.io/name: {{ include "sample-app.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Application environment, shared by the app container and the migrate
initContainer so both connect to the same database.
*/}}
{{- define "sample-app.env" -}}
- name: APP_ENV
  value: {{ .Values.app.env | quote }}
- name: APP_DEBUG
  value: {{ .Values.app.debug | quote }}
- name: APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ .Values.app.existingSecret | default (include "sample-app.fullname" .) }}
      key: APP_KEY
- name: DB_CONNECTION
  value: mysql
- name: DB_HOST
  value: {{ .Values.db.host | quote }}
- name: DB_PORT
  value: {{ .Values.db.port | quote }}
- name: DB_DATABASE
  value: {{ .Values.db.database | quote }}
- name: DB_USERNAME
  value: {{ .Values.db.username | quote }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.app.existingSecret | default (include "sample-app.fullname" .) }}
      key: DB_PASSWORD
{{- end }}
