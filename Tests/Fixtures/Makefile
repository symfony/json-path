override hash := b9d7153e58711ad38bb8e35ece69c13f4b2f7d63

.PHONY: cts.json
cts.json:
	curl -f https://raw.githubusercontent.com/jsonpath-standard/jsonpath-compliance-test-suite/$(hash)/cts.json -o cts.json

.PHONY: clean
clean:
	rm -f cts.json
