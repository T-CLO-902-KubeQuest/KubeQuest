#!/bin/bash

set -euo pipefail

export AWS_PAGER=""

GROUP_NAME="group-41"

echo "Fetching EC2 instances for group '$GROUP_NAME'..."

INSTANCE_IDS=$(aws ec2 describe-instances \
  --filters \
    "Name=tag:Group,Values=${GROUP_NAME}" \
    "Name=instance-state-name,Values=stopped" \
  --query "Reservations[*].Instances[*].InstanceId" \
  --output text)

if [ -z "$INSTANCE_IDS" ]; then
  echo "No stopped instances found for group '$GROUP_NAME'."
  exit 0
fi

echo "Starting instances:\n$INSTANCE_IDS"

aws ec2 start-instances --instance-ids $INSTANCE_IDS

echo "Waiting for instances to be running..."

aws ec2 wait instance-running --instance-ids $INSTANCE_IDS

echo "All instances for group '$GROUP_NAME' are running."
