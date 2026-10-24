# epidemiologia — build/test fence (L0-03). Shared text rules live in
# aps-common and are vendored here as a committed subtree. This app consumes
# `fold` only; tree-shaking keeps the rest out of the bundle. The sibling
# checkout must exist beside this repo (org layout).
APS ?= ../aps-common

.PHONY: test aps-sync aps-drift

test:
	npm test

aps-sync:
	@test -d "$(APS)/js" || { echo "aps-common not found at $(APS) — clone the sibling first"; exit 1; }
	rm -rf src/aps
	mkdir -p src/aps
	git -C "$(APS)" archive HEAD js | tar -x -C src/aps --strip-components=1
	rm -f src/aps/*.test.js

# src/aps is committed, so it can drift from the package the way any vendored
# copy drifts from its source. Until CI lands here, this target IS the gate
# (same posture farmacia's Makefile records).
aps-drift: aps-sync
	@drift="$$(git status --porcelain -- src/aps)"; \
	if [ -n "$$drift" ]; then \
		printf '%s\n' "$$drift"; \
		echo "::error::aps-common artifacts are behind the package. Run 'make aps-sync' and commit the result."; \
		exit 1; \
	fi
