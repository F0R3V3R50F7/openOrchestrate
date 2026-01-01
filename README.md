# openOrchestrate

**Local models, treated with respect.**

openOrchestrate is a complete **Local-First MoE AI Front-End** built as a desktop application using **phpDesktop-Chrome** and **llama.cpp**.

It is designed around one core idea:  
*local AI should behave like a well-engineered system, not a fragile demo.*

No cloud dependencies. No silent failures. No magical thinking about context or VRAM.  
Just disciplined routing, explicit constraints, and predictable behaviour.

---

## What is this?

openOrchestrate is **not just a chat UI**.

It is a **model-aware orchestration layer** that manages multiple local GGUF models, routes requests intelligently, preserves long-term context, and degrades gracefully on constrained hardware.

It exists because most local AI frontends:
- discard context silently
- break working paths between releases
- assume infinite VRAM
- prioritise features over invariants

openOrchestrate does the opposite.

---

## Core Features

### Intelligent Model Routing
Queries are automatically routed to the most appropriate expert model:
- general language
- code generation
- medical / health

---

### Velocity Index (Long-Term Memory)
Old messages are **archived, indexed, and recalled** when relevant instead of being blindly discarded.

This allows:
- smaller context windows
- better continuity
- fewer hallucinations caused by missing history

---

### Context Pruning (Not Amnesia)
When context limits are reached, conversations are **condensed intelligently**, preserving intent and key facts rather than dropping messages on the floor.

Context loss is managed deliberately, not silently.

---

### Multi-Model Execution
Run multiple GGUF models simultaneously with:
- intelligent GPU / CPU allocation
- optional CPU-only auxiliary models
- predictable VRAM usage

Designed for **real hardware**, not theoretical benchmarks.

---

### Local-Only by Design
- No API calls
- No telemetry
- No cloud dependencies
- No background services

Everything stays on *your* machine.

---

### File Attachments
Attach text files directly to conversations for analysis, summarisation, or reference — fully local.

---

## Architecture Overview

openOrchestrate is intentionally **boring where it matters**.

### Technology Stack

- **Frontend**: HTML / CSS / JavaScript  
  (~66,000 characters of styles; no frameworks, no bloat)
- **Backend**: PHP  
  (~40,000 characters of backend logic)
- **Runtime**: phpDesktop-Chrome
- **Inference Engine**: llama.cpp
- **Total Codebase**: ~313,000+ characters

No Electron. No Node. No mystery layers.

---

### Core Subsystems

- **Pipeline Engine**  
  Multi-stage request processing with clear separation of concerns.

- **Llama Governor**  
  Central authority for model lifecycle, routing, and resource limits.

- **Velocity Index**  
  Long-term memory system for recall and context reconstruction.

Each subsystem has a defined role. Nothing is accidental.

---

## Supported Models

Any GGUF model compatible with llama.cpp, including:

- General LLMs (Llama 2/3, Mistral, Qwen, Gemma)
- Code models (CodeLlama, DeepSeek-Coder)
- Medical / research models (Meditron, BioMistral)
- Fully custom expert configurations

No hardcoded assumptions about model size or vendor.

---

## Design Philosophy

- **Constraints are real**
- **Regression is failure**
- **Working paths are sacred**
- **Frontends are part of the intelligence**
- **Graceful degradation beats silent faliure**

If a feature breaks an invariant, it does not ship.

---

## Status

This project is under **active development**.

The focus is stability, coherence, and architectural clarity — not rapid feature churn.

Expect:
- deliberate changes
- conservative releases
- boring upgrades

---

## Why this exists

Because local AI deserves tooling that respects:
- limited VRAM
- limited context
- user trust
- and reality itself

---

<p align="center">
  <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/d/dc/Flag_of_Wales.svg/320px-Flag_of_Wales.svg.png" alt="Welsh Flag" width="120">
</p>

<p align="center">
  <strong>Made in Wales with love.</strong>
</p>
