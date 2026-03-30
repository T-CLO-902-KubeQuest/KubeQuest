#!/bin/bash

set -euo pipefail

export AWS_PAGER=""

GROUP_NAME="group-41"

echo "Fetching EC2 instances for group '$GROUP_NAME'..."

INSTANCE_IDS=$(aws ec2 describe-instances \
  --filters \
    "Name=tag:Group,Values=${GROUP_NAME}" \
    "Name=instance-state-name,Values=running" \
  --query "Reservations[*].Instances[*].InstanceId" \
  --output text)

if [ -z "$INSTANCE_IDS" ]; then
  echo "No running instances found for group '$GROUP_NAME'."
  exit 0
fi

echo "Stopping instances:\n$INSTANCE_IDS"

aws ec2 stop-instances --instance-ids $INSTANCE_IDS

echo "Waiting for instances to be stopped..."

aws ec2 wait instance-stopped --instance-ids $INSTANCE_IDS

echo "All instances for group '$GROUP_NAME' are stopped."
